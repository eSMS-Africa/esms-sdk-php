<?php

declare(strict_types=1);

namespace Esms\Resource;

use Esms\HttpClient;

/** Account balance and credit. */
class Balance
{
    /** @var HttpClient */
    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Get the current account balance and an SMS estimate.
     *
     * @return array{balance:float,currency:string,sms_estimate?:int}
     */
    public function get(): array
    {
        return $this->http->request('GET', '/balance');
    }
}
