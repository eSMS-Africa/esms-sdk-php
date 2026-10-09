<?php

declare(strict_types=1);

namespace Esms\Resource;

use Esms\HttpClient;

/** Manage the opt-out (STOP / DND) list. */
class OptOuts
{
    /** @var HttpClient */
    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /** List numbers that have opted out of your messages (default 200, max 1000). */
    public function list(?int $limit = null): array
    {
        $r = $this->http->request('GET', '/opt-outs', ['limit' => $limit]);

        return $r['opt_outs'] ?? [];
    }

    /** Manually add a number to your opt-out list. */
    public function add(string $phone): array
    {
        return $this->http->request('POST', '/opt-outs', null, ['phone' => $phone]);
    }

    /** Remove a number from your opt-out list. */
    public function remove(string $phone): array
    {
        return $this->http->request('DELETE', '/opt-outs/' . rawurlencode($phone));
    }
}
