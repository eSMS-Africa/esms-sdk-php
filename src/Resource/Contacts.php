<?php

declare(strict_types=1);

namespace Esms\Resource;

use Esms\HttpClient;

/**
 * Contact book: everyone across your groups, addressed by E.164 phone.
 * Methods return the API JSON as arrays (snake_case keys).
 */
class Contacts
{
    /** @var HttpClient */
    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /** Filters: search, group_id, opted_out ('true'|'false'), page, limit. @return array<string,mixed> */
    public function list(array $query = []): array
    {
        return $this->http->request('GET', '/contacts', $query);
    }

    /** @return array<string,mixed> */
    public function get(string $phone): array
    {
        return $this->http->request('GET', '/contacts/' . rawurlencode((string) $phone));
    }

    /** params: phone, group_ids, name?, fields?. @return array<string,mixed> */
    public function upsert(array $params): array
    {
        return $this->http->request('POST', '/contacts', null, $params);
    }

    /** Fields are merged. @return array<string,mixed> */
    public function update(string $phone, array $params): array
    {
        return $this->http->request('PATCH', '/contacts/' . rawurlencode((string) $phone), null, $params);
    }

    /** Delete from every group. */
    public function delete(string $phone): void
    {
        $this->http->request('DELETE', '/contacts/' . rawurlencode((string) $phone));
    }
}
