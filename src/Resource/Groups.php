<?php

declare(strict_types=1);

namespace Esms\Resource;

use Esms\HttpClient;

/**
 * Contact groups (lists).
 * Methods return the API JSON as arrays (snake_case keys).
 */
class Groups
{
    /** @var HttpClient */
    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /** @return array<string,mixed> */
    public function list(?string $q = null): array
    {
        return $this->http->request('GET', '/groups', ['q' => $q]);
    }

    /** @return array<string,mixed> */
    public function create(string $name, ?array $tags = null): array
    {
        return $this->http->request('POST', '/groups', null, ['name' => $name, 'tags' => $tags]);
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        return $this->http->request('GET', '/groups/' . $id);
    }

    /** @return array<string,mixed> */
    public function update(int $id, array $params): array
    {
        return $this->http->request('PATCH', '/groups/' . $id, null, $params);
    }

    /** @return array<string,mixed> */
    public function delete(int $id): array
    {
        return $this->http->request('DELETE', '/groups/' . $id);
    }

    /** @return array<string,mixed> */
    public function contacts(int $id, array $query = []): array
    {
        return $this->http->request('GET', "/groups/{$id}/contacts", $query);
    }

    /** Up to 10,000 [[phone, name?, fields?]]. @return array<string,mixed> */
    public function addContacts(int $id, array $contacts, ?string $defaultCountry = null): array
    {
        return $this->http->request('POST', "/groups/{$id}/contacts", null, ['contacts' => $contacts, 'default_country' => $defaultCountry]);
    }

    /** @return array<string,mixed> */
    public function removeContact(int $id, string $phone): array
    {
        return $this->http->request('DELETE', "/groups/{$id}/contacts/" . rawurlencode((string) $phone));
    }
}
