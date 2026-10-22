<?php

declare(strict_types=1);

namespace Esms\Tests;

use Esms\Client;
use Esms\HttpClient;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Records every call (JSON and raw multipart). */
class RecordingHttp extends HttpClient
{
    /** @var array<int,array<string,mixed>> */
    public $calls = [];

    public function __construct()
    {
        parent::__construct('esms_test_abc', 'https://sms.esmsafrica.io/api', 30, 0);
    }

    public function request(string $method, string $path, ?array $query = null, ?array $body = null, ?array $extraHeaders = null)
    {
        $this->calls[] = compact('method', 'path', 'query', 'body', 'extraHeaders');
        return ['id' => 1];
    }

    public function requestRaw(string $method, string $path, string $payload, string $contentType)
    {
        $this->calls[] = compact('method', 'path', 'payload', 'contentType');
        return ['id' => 'imp_1'];
    }
}

class V2Test extends TestCase
{
    /** @return array{0:Client,1:RecordingHttp} */
    private function client(): array
    {
        $client = new Client('esms_test_abc');
        $fake = new RecordingHttp();
        foreach (['messages', 'campaigns', 'imports', 'jobs', 'contacts', 'groups', 'templates'] as $res) {
            $prop = new ReflectionProperty($client->$res, 'http');
            $prop->setAccessible(true);
            $prop->setValue($client->$res, $fake);
        }
        return [$client, $fake];
    }

    public function testSendBatchAddsIdempotencyKeyAndKeepsVars(): void
    {
        [$c, $h] = $this->client();
        $c->messages->sendBatch([
            'template' => 'Hi {first_name}, owe {amount}',
            'recipients' => [['to' => '+256772123456', 'vars' => ['amount' => '1,500']]],
            'throttle_per_minute' => 100,
        ]);
        $call = $h->calls[0];
        $this->assertSame('/messages/batch', $call['path']);
        $this->assertSame('1,500', $call['body']['recipients'][0]['vars']['amount']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $call['extraHeaders']['Idempotency-Key']);

        $c->messages->sendBatch(['messages' => [['to' => '+1', 'text' => 'x']], 'idempotency_key' => 'k-1']);
        $this->assertSame('k-1', $h->calls[1]['extraHeaders']['Idempotency-Key']);
        $this->assertArrayNotHasKey('idempotency_key', $h->calls[1]['body']);
    }

    public function testCampaignPaths(): void
    {
        [$c, $h] = $this->client();
        $c->campaigns->create(['name' => 'Dues', 'text' => 'Hi', 'idempotency_key' => 'c-1']);
        $c->campaigns->prepare(3);
        $c->campaigns->review(3);
        $c->campaigns->launch(3);
        $c->campaigns->approve(3, 'ok');
        $c->campaigns->retryFailed(3);
        $c->campaigns->recipients(3, ['status' => 'failed']);
        $got = array_map(static function ($x) { return $x['method'] . ' ' . $x['path']; }, $h->calls);
        $this->assertSame([
            'POST /campaigns', 'POST /campaigns/3/prepare', 'GET /campaigns/3/review', 'POST /campaigns/3/launch',
            'POST /campaigns/3/approve', 'POST /campaigns/3/retry-failed', 'GET /campaigns/3/recipients',
        ], $got);
        $this->assertSame('c-1', $h->calls[0]['extraHeaders']['Idempotency-Key']);
        $this->assertSame(['note' => 'ok'], $h->calls[4]['body']);
        $this->assertSame(['status' => 'failed'], $h->calls[6]['query']);
    }

    public function testImportUploadIsMultipart(): void
    {
        [$c, $h] = $this->client();
        $path = tempnam(sys_get_temp_dir(), 'esms') . '.csv';
        file_put_contents($path, "phone\n0772123456\n");
        $c->imports->upload($path, null, 'UG');
        $call = $h->calls[0];
        $this->assertStringStartsWith('multipart/form-data; boundary=', $call['contentType']);
        $this->assertStringContainsString('filename="' . basename($path) . '"', $call['payload']);
        $this->assertStringContainsString("0772123456", $call['payload']);
        $this->assertStringContainsString('name="default_country"', $call['payload']);
        $c->imports->process('imp_1', ['phone' => 'Mobile'], ['default_country' => 'UG']);
        $this->assertSame(['mapping' => ['phone' => 'Mobile'], 'default_country' => 'UG'], $h->calls[1]['body']);
    }

    public function testContactsGroupsTemplates(): void
    {
        [$c, $h] = $this->client();
        $c->contacts->get('+256772123456');
        $c->groups->removeContact(5, '+256772123456');
        $c->templates->useStarter('payment-reminder');
        $this->assertSame('/contacts/%2B256772123456', $h->calls[0]['path']);
        $this->assertSame('/groups/5/contacts/%2B256772123456', $h->calls[1]['path']);
        $this->assertSame('/templates/starter-pack/payment-reminder', $h->calls[2]['path']);
    }

    public function testCampaignWebhookVerifies(): void
    {
        $body = '{"event":"campaign.completed","campaign_id":1}';
        $sig = 'sha256=' . hash_hmac('sha256', $body, 'whsec_x');
        $this->assertTrue(Client::verifyWebhook($body, $sig, 'whsec_x'));
    }
}
