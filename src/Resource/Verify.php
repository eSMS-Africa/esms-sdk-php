<?php

declare(strict_types=1);

namespace Esms\Resource;

use Esms\HttpClient;

/** Managed OTP verification — we generate, send, and check the code. */
class Verify
{
    /** @var HttpClient */
    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Send a verification code to a phone number.
     *
     * @param array{sender_id?:string,code_length?:int,expiry_seconds?:int,template?:string} $options
     */
    public function start(string $to, array $options = []): array
    {
        $body = array_filter([
            'to'             => $to,
            'sender_id'      => $options['sender_id'] ?? null,
            'code_length'    => $options['code_length'] ?? null,
            'expiry_seconds' => $options['expiry_seconds'] ?? null,
            'template'       => $options['template'] ?? null,
        ], static function ($v) {
            return $v !== null;
        });

        return $this->http->request('POST', '/verify/start', null, $body);
    }

    /**
     * Check a code the user entered. Returns `['status' => 'approved'|'pending'|...]`.
     *
     * @param array{verification_id?:string,to?:string} $options
     */
    public function check(string $code, array $options = []): array
    {
        $body = array_filter([
            'code'            => $code,
            'verification_id' => $options['verification_id'] ?? null,
            'to'              => $options['to'] ?? null,
        ], static function ($v) {
            return $v !== null;
        });

        return $this->http->request('POST', '/verify/check', null, $body);
    }
}
