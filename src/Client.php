<?php

declare(strict_types=1);

namespace Esms;

use DateTimeInterface;

/**
 * The eSMS Africa SMS client.
 *
 * Example:
 *
 *     $esms = new \Esms\Client('esms_live_...');
 *     $res = $esms->messages->send(['to' => '+256700000000', 'text' => 'Hi']);
 *     echo $res['id'], ' ', $res['status'];
 */
class Client
{
    public const DEFAULT_BASE_URL = 'https://sms.esmsafrica.io/api';

    /** @var HttpClient */
    private $http;

    /** @var Resource\Messages */
    public $messages;

    /** @var Resource\Balance */
    public $balance;

    /** @var Resource\Routes */
    public $routes;

    /**
     * @param array{base_url?:string,timeout?:int,max_retries?:int} $options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException(
                'An API key is required. Create one in the eSMS dashboard under Developers -> API Keys.'
            );
        }
        $this->http = new HttpClient(
            $apiKey,
            $options['base_url'] ?? self::DEFAULT_BASE_URL,
            $options['timeout'] ?? 30,
            $options['max_retries'] ?? 2
        );
        $this->messages = new Resource\Messages($this->http);
        $this->balance = new Resource\Balance($this->http);
        $this->routes = new Resource\Routes($this->http);
    }

    public function getBaseUrl(): string
    {
        return $this->http->getBaseUrl();
    }
}
