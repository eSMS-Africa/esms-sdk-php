<?php

declare(strict_types=1);

namespace Esms\Resource;

use Esms\HttpClient;

/** Available SMS routes and their pricing. */
class Routes
{
    /** @var HttpClient */
    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * List all active routes (one per country you can reach).
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(): array
    {
        return $this->http->request('GET', '/routes');
    }
}
