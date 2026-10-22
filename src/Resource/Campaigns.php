<?php

declare(strict_types=1);

namespace Esms\Resource;

use Esms\HttpClient;

/**
 * Campaigns: draft -> preview / prepare / review -> test -> launch, plus reports.
 * Methods return the API JSON as arrays (snake_case keys).
 */
class Campaigns
{
    /** @var HttpClient */
    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /** Create a draft (launch => true creates and launches). @param array<string,mixed> $params @return array<string,mixed> */
    public function create(array $params): array
    {
        $key = $params['idempotency_key'] ?? null;
        unset($params['idempotency_key']);
        return $this->http->request('POST', '/campaigns', null, $params, $key ? ['Idempotency-Key' => $key] : null);
    }

    /** Filters: status, q, tag, page, limit, include_runs. @return array<string,mixed> */
    public function list(array $query = []): array
    {
        return $this->http->request('GET', '/campaigns', $query);
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        return $this->http->request('GET', '/campaigns/' . $id);
    }

    /** Partial update of a draft. @return array<string,mixed> */
    public function update(int $id, array $params): array
    {
        return $this->http->request('PATCH', '/campaigns/' . $id, null, $params);
    }

    /** @return array<string,mixed> */
    public function delete(int $id): array
    {
        return $this->http->request('DELETE', '/campaigns/' . $id);
    }

    /** First N rendered messages + longest-message counter. @return array<string,mixed> */
    public function preview(int $id, ?string $text = null, ?int $n = null): array
    {
        return $this->http->request('POST', "/campaigns/{$id}/preview", null, ['text' => $text, 'n' => $n]);
    }

    /** Build the recipient list and exact estimate. @return array<string,mixed> */
    public function prepare(int $id): array
    {
        return $this->post($id, 'prepare');
    }

    /** @return array<string,mixed> */
    public function review(int $id): array
    {
        return $this->http->request('GET', "/campaigns/{$id}/review");
    }

    /** Send the first rendered message to up to 3 of your own numbers. @param string[] $to @return array<string,mixed> */
    public function test(int $id, array $to): array
    {
        return $this->post($id, 'test', ['to' => $to]);
    }

    /** Send now, schedule, or submit for approval. @return array<string,mixed> */
    public function launch(int $id): array
    {
        return $this->post($id, 'launch');
    }

    /** @return array<string,mixed> */
    public function approve(int $id, ?string $note = null): array
    {
        return $this->post($id, 'approve', ['note' => $note]);
    }

    /** @return array<string,mixed> */
    public function reject(int $id, ?string $note = null): array
    {
        return $this->post($id, 'reject', ['note' => $note]);
    }

    /** @return array<string,mixed> */
    public function pause(int $id): array
    {
        return $this->post($id, 'pause');
    }

    /** @return array<string,mixed> */
    public function resume(int $id): array
    {
        return $this->post($id, 'resume');
    }

    /** @return array<string,mixed> */
    public function cancel(int $id): array
    {
        return $this->post($id, 'cancel');
    }

    /** Send again to failed recipients (charged like a new send). @return array<string,mixed> */
    public function retryFailed(int $id): array
    {
        return $this->post($id, 'retry-failed');
    }

    /** @return array<string,mixed> */
    public function duplicate(int $id): array
    {
        return $this->post($id, 'duplicate');
    }

    /** @return array<string,mixed> */
    public function report(int $id): array
    {
        return $this->http->request('GET', "/campaigns/{$id}/report");
    }

    /** @return array<string,mixed> */
    public function stats(int $id): array
    {
        return $this->http->request('GET', "/campaigns/{$id}/stats");
    }

    /** Filters: status (delivered|failed|sent|pending|skipped|cancelled), country, q, page, limit. @return array<string,mixed> */
    public function recipients(int $id, array $query = []): array
    {
        return $this->http->request('GET', "/campaigns/{$id}/recipients", $query);
    }

    /** @return array<string,mixed> */
    public function runs(int $id): array
    {
        return $this->http->request('GET', "/campaigns/{$id}/runs");
    }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    private function post(int $id, string $action, ?array $body = null): array
    {
        return $this->http->request('POST', "/campaigns/{$id}/{$action}", null, $body);
    }
}
