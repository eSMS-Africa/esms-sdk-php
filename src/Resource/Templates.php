<?php

declare(strict_types=1);

namespace Esms\Resource;

use Esms\HttpClient;

/**
 * Message templates with merge fields and a starter pack.
 * Methods return the API JSON as arrays (snake_case keys).
 */
class Templates
{
    /** @var HttpClient */
    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /** Filters: category, message_type, q. @return array<string,mixed> */
    public function list(array $query = []): array
    {
        return $this->http->request('GET', '/templates', $query);
    }

    /** params: name, text, category?, message_type?, description?, visibility?, sender_id?. @return array<string,mixed> */
    public function create(array $params): array
    {
        return $this->http->request('POST', '/templates', null, $params);
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        return $this->http->request('GET', '/templates/' . $id);
    }

    /** Partial update. @return array<string,mixed> */
    public function update(int $id, array $params): array
    {
        return $this->http->request('PATCH', '/templates/' . $id, null, $params);
    }

    public function delete(int $id): void
    {
        $this->http->request('DELETE', '/templates/' . $id);
    }

    /** @return array<string,mixed> */
    public function starterPack(): array
    {
        return $this->http->request('GET', '/templates/starter-pack');
    }

    /** Copy a starter template into your library. @return array<string,mixed> */
    public function useStarter(string $key): array
    {
        return $this->http->request('POST', '/templates/starter-pack/' . rawurlencode((string) $key));
    }

    /** options: vars, name, phone. @return array<string,mixed> */
    public function render(string $text, array $options = []): array
    {
        return $this->http->request('POST', '/templates/render', null, ['text' => $text] + $options);
    }
}
