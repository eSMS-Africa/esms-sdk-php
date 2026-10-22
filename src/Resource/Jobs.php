<?php

declare(strict_types=1);

namespace Esms\Resource;

use Esms\HttpClient;

/**
 * Background job progress.
 * Methods return the API JSON as arrays (snake_case keys).
 */
class Jobs
{
    /** @var HttpClient */
    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        return $this->http->request('GET', '/jobs/' . $id);
    }
}
