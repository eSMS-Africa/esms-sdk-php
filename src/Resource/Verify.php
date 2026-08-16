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
     * @param array{app_id?:string,sender_id?:string,code_length?:int,expiry_seconds?:int,template?:string,idempotency_key?:string} $options
     */
    public function start(string $to, array $options = []): array
    {
        $body = array_filter([
            'to'             => $to,
            'app_id'         => $options['app_id'] ?? null,
            'sender_id'      => $options['sender_id'] ?? null,
            'code_length'    => $options['code_length'] ?? null,
            'expiry_seconds' => $options['expiry_seconds'] ?? null,
            'template'       => $options['template'] ?? null,
        ], static function ($v) {
            return $v !== null;
        });
        $headers = isset($options['idempotency_key']) ? ['Idempotency-Key' => $options['idempotency_key']] : null;

        return $this->http->request('POST', '/verify/start', null, $body, $headers);
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

    /** Fetch a verification's status without consuming an attempt. */
    public function get(string $verificationId): array
    {
        return $this->http->request('GET', '/verify/' . $verificationId);
    }

    /** Send a fresh code for the same verification. */
    public function resend(string $verificationId): array
    {
        return $this->http->request('POST', '/verify/' . $verificationId . '/resend');
    }

    /** Void an in-flight verification. */
    public function cancel(string $verificationId): array
    {
        return $this->http->request('POST', '/verify/' . $verificationId . '/cancel');
    }

    /**
     * List your verifications (most recent first).
     *
     * @param array{status?:string,app_id?:string,to?:string,page?:int,limit?:int} $query
     */
    public function list(array $query = []): array
    {
        return $this->http->request('GET', '/verify', $query);
    }

    // ---- Verify Apps ----

    /** List your Verify Apps. */
    public function listApps(): array
    {
        return $this->http->request('GET', '/verify/apps');
    }

    /** @param array<string,mixed> $body */
    public function createApp(array $body): array
    {
        return $this->http->request('POST', '/verify/apps', null, $body);
    }

    public function getApp(string $id): array
    {
        return $this->http->request('GET', '/verify/apps/' . $id);
    }

    /**
     * Update a Verify App (full replace - send all fields; name is required).
     * @param array<string,mixed> $body
     */
    public function updateApp(string $id, array $body): array
    {
        return $this->http->request('PATCH', '/verify/apps/' . $id, null, $body);
    }

    public function deleteApp(string $id): array
    {
        return $this->http->request('DELETE', '/verify/apps/' . $id);
    }

    /** Per-app verification stats. */
    public function appStats(string $id, int $days = 30): array
    {
        return $this->http->request('GET', '/verify/apps/' . $id . '/stats', ['days' => $days]);
    }
}
