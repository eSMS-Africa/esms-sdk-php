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

    public function request(string $method, string $path, ?array $query = null, ?array $body = null)
    {
        $this->lastCall = compact('method', 'path', 'query', 'body');
        return ($this->handler)($method, $path, $query, $body);
    }
}

class ClientTest extends TestCase
{
    private function clientWith(callable $handler): array
    {
        $client = new Client('esms_test_abc');
        $fake = new FakeHttp($handler);
        // Swap the private $http and rebuild resources against it.
        foreach (['messages', 'balance', 'routes'] as $res) {
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
        $e = EsmsException::fromResponse(422, ['detail' => [
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
}
