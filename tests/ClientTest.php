<?php

declare(strict_types=1);

namespace Esms\Tests;

use Esms\Client;
use Esms\Exception\AuthenticationException;
use Esms\Exception\EsmsException;
use Esms\Exception\InsufficientBalanceException;
use Esms\Exception\NotFoundException;
use Esms\HttpClient;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A fake HttpClient that returns scripted responses instead of doing HTTP.
 */
class FakeHttp extends HttpClient
{
    /** @var callable */
    private $handler;
    /** @var array<string,mixed> */
    public $lastCall = [];

    public function __construct(callable $handler)
    {
        parent::__construct('esms_test_abc', 'https://sms.esmsafrica.io/api', 30, 0);
        $this->handler = $handler;
    }

    public function request(string $method, string $path, ?array $query = null, ?array $body = null, ?array $extraHeaders = null)
    {
        $this->lastCall = compact('method', 'path', 'query', 'body', 'extraHeaders');
        return ($this->handler)($method, $path, $query, $body);
    }
}

/**
 * An HttpClient whose transport returns scripted [status, body, requestId,
 * curlErr, retryAfter] tuples, so the real request()/retry logic runs.
 */
class ScriptedTransport extends HttpClient
{
    /** @var array<int,array{0:int,1:string,2:?string,3:?string,4:?string}> */
    public $script = [];
    /** @var int */
    public $calls = 0;

    public function __construct(int $maxRetries, array $script)
    {
        parent::__construct('esms_test_abc', 'https://example.invalid/api', 5, $maxRetries);
        $this->script = $script;
    }

    protected function send(string $method, string $url, array $headers, ?string $payload): array
    {
        $r = $this->script[min($this->calls, count($this->script) - 1)];
        $this->calls++;
        return $r;
    }
}

class ClientTest extends TestCase
{
    private function clientWith(callable $handler): array
    {
        $client = new Client('esms_test_abc');
        $fake = new FakeHttp($handler);
        // Swap the private $http and rebuild resources against it.
        foreach (['messages', 'balance', 'routes', 'verify', 'optOuts'] as $res) {
            $prop = new ReflectionProperty($client->$res, 'http');
            $prop->setAccessible(true);
            $prop->setValue($client->$res, $fake);
        }
        return [$client, $fake];
    }

    public function testRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('');
    }

    public function testDefaultBaseUrl(): void
    {
        $client = new Client('esms_live_x');
        $this->assertSame('https://sms.esmsafrica.io/api', $client->getBaseUrl());
    }

    public function testSendMessage(): void
    {
        [$client, $fake] = $this->clientWith(function ($method, $path, $query, $body) {
            $this->assertSame('POST', $method);
            $this->assertSame('/messages/send', $path);
            return [
                'id' => 'msg_1',
                'status' => 'submitted',
                'cost_currency' => 'KES',
                'balance_after' => 9.6,
            ];
        });
        $res = $client->messages->send(['to' => '+256700000000', 'text' => 'Hi']);
        $this->assertSame('msg_1', $res['id']);
        $this->assertSame('submitted', $res['status']);
        $this->assertSame('+256700000000', $fake->lastCall['body']['to']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $fake->lastCall['extraHeaders']['Idempotency-Key']
        );
    }

    public function testScheduleSetsMode(): void
    {
        [$client, $fake] = $this->clientWith(function ($method, $path, $query, $body) {
            return ['id' => 'm', 'status' => 'scheduled'];
        });
        $client->messages->schedule([
            'to' => '+256700000000',
            'text' => 'x',
            'scheduled_at' => '2026-08-01T09:00:00Z',
        ]);
        $this->assertSame('scheduled', $fake->lastCall['body']['schedule_mode']);
        $this->assertSame('2026-08-01T09:00:00Z', $fake->lastCall['body']['scheduled_at']);
    }

    public function testFromResponseMapsAuth(): void
    {
        $e = EsmsException::fromResponse(401, ['detail' => 'Not authenticated'], 'req_1');
        $this->assertInstanceOf(AuthenticationException::class, $e);
        $this->assertSame(401, $e->getStatus());
        $this->assertSame('req_1', $e->getRequestId());
    }

    public function testFromResponseMapsNotFound(): void
    {
        $e = EsmsException::fromResponse(404, ['detail' => 'Message not found']);
        $this->assertInstanceOf(NotFoundException::class, $e);
    }

    public function testFromResponseInsufficientBalance(): void
    {
        $e = EsmsException::fromResponse(402, ['detail' => [
            'code' => 'insufficient_balance',
            'message' => 'Balance KES 1 < cost KES 5',
            'balance' => 1,
            'cost' => 5,
            'currency' => 'KES',
        ]]);
        $this->assertInstanceOf(InsufficientBalanceException::class, $e);
        /** @var InsufficientBalanceException $e */
        $this->assertSame(1, $e->getBalance());
        $this->assertSame(5, $e->getCost());
        $this->assertSame('KES', $e->getCurrency());
        $this->assertSame('insufficient_balance', $e->getApiCode());
    }

    public function testListAndRoutes(): void
    {
        [$client] = $this->clientWith(function ($method, $path) {
            if ($path === '/routes') {
                return [[
                    'code' => 'ESMS_UG',
                    'country_name' => 'Uganda',
                    'price_per_segment' => 35,
                    'is_active' => true,
                ]];
            }
            return ['messages' => [], 'total' => 0, 'page' => 0, 'limit' => 20];
        });
        $routes = $client->routes->list();
        $this->assertSame('ESMS_UG', $routes[0]['code']);
        $list = $client->messages->list();
        $this->assertSame(0, $list['total']);
    }

    public function testFromResponseEnvelopeAndValidationList(): void
    {
        $e = EsmsException::fromResponse(422, [
            'error' => ['code' => 'validation_error', 'message' => 'Request validation failed', 'request_id' => 'r1'],
            'detail' => [['loc' => ['body', 'to'], 'msg' => 'Field required', 'type' => 'missing']],
        ]);
        $this->assertInstanceOf(\Esms\Exception\InvalidRequestException::class, $e);
        $this->assertSame('validation_error', $e->getApiCode());
        $this->assertSame('Request validation failed: body.to: Field required', $e->getMessage());
        $this->assertSame('r1', $e->getRequestId());

        $e = EsmsException::fromResponse(429, ['error' => 'Rate limit exceeded: 30 per 1 minute']);
        $this->assertInstanceOf(\Esms\Exception\RateLimitException::class, $e);
        $this->assertSame('Rate limit exceeded: 30 per 1 minute', $e->getMessage());

        $e = EsmsException::fromResponse(402, ['detail' => [
            'code' => 'insufficient_balance', 'message' => 'no', 'required' => 5, 'available' => 1,
        ]]);
        /** @var InsufficientBalanceException $e */
        $this->assertSame(1, $e->getBalance());
        $this->assertSame(5, $e->getCost());
    }

    public function testBulkFiltersAndDeleteApp(): void
    {
        [$client, $fake] = $this->clientWith(function ($method, $path) {
            if ($path === '/messages/send-bulk') {
                return ['batch_id' => 'b1', 'total_recipients' => 1, 'estimated_cost' => 0.5, 'status' => 'processing'];
            }
            if ($method === 'DELETE') {
                return null;
            }
            return ['messages' => [], 'total' => 0, 'page' => 0, 'limit' => 20];
        });
        $res = $client->messages->sendBulk([], 'hi', null, null, ['recipients' => [['to' => '+256700000000']]]);
        $this->assertSame('b1', $res['batch_id']);
        $this->assertSame([['to' => '+256700000000']], $fake->lastCall['body']['recipients']);
        $this->assertNull($fake->lastCall['body']['contact_list_ids']);

        $client->messages->list(0, 20, null, ['batch_id' => 'b1']);
        $this->assertSame('b1', $fake->lastCall['query']['batch_id']);

        $client->verify->deleteApp('app_1');
        $this->assertSame('/verify/apps/app_1', $fake->lastCall['path']);
    }
    public function testInsufficientBalanceCurrencyFromLiveMessage(): void
    {
        // The live API states the currency only inside the message text.
        $e = EsmsException::fromResponse(402, [
            'error' => ['code' => 'insufficient_balance', 'message' => 'x', 'request_id' => 'r'],
            'detail' => [
                'code' => 'insufficient_balance',
                'message' => 'Insufficient balance. Required KES 5.0, available KES 1.0',
                'required' => 5.0,
                'available' => 1.0,
            ],
        ]);
        $this->assertInstanceOf(InsufficientBalanceException::class, $e);
        /** @var InsufficientBalanceException $e */
        $this->assertSame('KES', $e->getCurrency());
        $this->assertSame(1.0, $e->getBalance());
        $this->assertSame(5.0, $e->getCost());
    }

    public function testNonJson2xxThrows(): void
    {
        $http = new ScriptedTransport(0, [[200, '<html>gateway</html>', 'rid', null, null]]);
        $this->expectException(EsmsException::class);
        $this->expectExceptionMessageMatches('/non-JSON/');
        $http->request('GET', '/balance');
    }

    public function testInvalidUtf8BodyFailsBeforeSending(): void
    {
        $http = new ScriptedTransport(0, [[200, '{}', null, null, null]]);
        try {
            $http->request('POST', '/messages/send', null, ['to' => '+256700000000', 'text' => "\xB1\x31"]);
            $this->fail('expected an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, $http->calls);
        }
    }

    public function testRetryPolicyAndRetryAfter(): void
    {
        // POST without an Idempotency-Key is not retried after a 5xx.
        $http = new ScriptedTransport(2, [[500, '{"detail":"boom"}', null, null, null]]);
        try {
            $http->request('POST', '/messages/m1/retry');
            $this->fail('expected an exception');
        } catch (EsmsException $e) {
            $this->assertSame(500, $e->getStatus());
        }
        $this->assertSame(1, $http->calls);

        // With a key it is retried, honouring Retry-After: 0.
        $http = new ScriptedTransport(1, [
            [503, '{}', null, null, '0'],
            [200, '{"id":"m","status":"submitted"}', null, null, null],
        ]);
        $t = microtime(true);
        $res = $http->request('POST', '/messages/send', null, ['to' => '+1', 'text' => 'x'], ['Idempotency-Key' => 'k']);
        $this->assertSame('m', $res['id']);
        $this->assertSame(2, $http->calls);
        $this->assertLessThan(0.4, microtime(true) - $t);
        $this->assertSame(3.0, $http->backoff(0, '3'));
        $this->assertSame(20.0, $http->backoff(0, '120'));
    }

    public function testSendAndBulkAdvancedOptions(): void
    {
        [$client, $fake] = $this->clientWith(function () {
            return ['id' => 'm', 'status' => 'submitted'];
        });
        $client->messages->send(['to' => '+256700000000', 'text' => 'x', 'flash' => true, 'validity_period' => 60, 'priority' => 1]);
        $this->assertTrue($fake->lastCall['body']['flash']);
        $this->assertSame(60, $fake->lastCall['body']['validity_period']);
        $client->messages->sendBulk([], 'x', null, null, ['recipients' => [['to' => '+256700000000']], 'skip_unavailable' => true]);
        $this->assertTrue($fake->lastCall['body']['skip_unavailable']);
    }
}
