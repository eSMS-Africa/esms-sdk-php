<?php

declare(strict_types=1);

namespace Esms;

use Esms\Exception\ConnectionException;
use Esms\Exception\EsmsException;

/**
 * Thin cURL-based HTTP layer: auth header, JSON encode/decode, timeouts,
 * error mapping, and retry-with-backoff for transient failures.
 *
 * @internal
 */
class HttpClient
{
    private const VERSION = '1.0.0';

    /** Methods that are safe to repeat after a 5xx or network error. */
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    /** @var string */
    private $apiKey;
    /** @var string */
    private $baseUrl;
    /** @var int */
    private $timeout;
    /** @var int */
    private $maxRetries;

    public function __construct(string $apiKey, string $baseUrl, int $timeout, int $maxRetries)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * A random idempotency key (UUID v4).
     */
    public static function newIdempotencyKey(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * POSTs are only retried after a 5xx or network error when they carry an
     * Idempotency-Key, so a retry can never send or charge twice. 429 is
     * always retried (the request was rejected before it ran).
     *
     * @param array<string,mixed>|null $query
     * @param array<string,mixed>|null $body
     * @param array<string,string|null>|null $extraHeaders
     * @return mixed
     */
    public function request(string $method, string $path, ?array $query = null, ?array $body = null, ?array $extraHeaders = null)
    {
        $url = $this->baseUrl . $path;
        if ($query) {
            $clean = array_filter($query, static function ($v) {
                return $v !== null;
            });
            if ($clean) {
                $url .= '?' . http_build_query($clean);
            }
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: esms-php/' . self::VERSION,
        ];
        if ($extraHeaders) {
            foreach ($extraHeaders as $k => $v) {
                if ($v !== null) {
                    $headers[] = $k . ': ' . $v;
                }
            }
        }
        $payload = null;
        if ($body !== null) {
            $clean = array_filter($body, static function ($v) {
                return $v !== null;
            });
            $payload = json_encode($clean);
            $headers[] = 'Content-Type: application/json';
        }

        $retrySafe = in_array(strtoupper($method), self::IDEMPOTENT_METHODS, true);
        if ($extraHeaders) {
            foreach ($extraHeaders as $k => $v) {
                if ($v !== null && strtolower((string) $k) === 'idempotency-key') {
                    $retrySafe = true;
                }
            }
        }

        $lastError = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            [$status, $rawBody, $requestId, $curlErr] = $this->send($method, $url, $headers, $payload);

            if ($curlErr !== null) {
                // The request may already have been processed; only retry when safe.
                $lastError = $curlErr;
                if ($retrySafe && $attempt < $this->maxRetries) {
                    usleep((int) ($this->backoff($attempt) * 1_000_000));
                    continue;
                }
                throw new ConnectionException("Could not reach the eSMS API: {$curlErr}");
            }

            $parsed = $rawBody === '' ? null : $this->safeJson($rawBody);

            if ($status >= 200 && $status < 300) {
                return $parsed;
            }

            if (($status === 429 || ($status >= 500 && $retrySafe)) && $attempt < $this->maxRetries) {
                $lastError = "HTTP {$status}";
                usleep((int) ($this->backoff($attempt) * 1_000_000));
                continue;
            }

            throw EsmsException::fromResponse($status, $parsed, $requestId);
        }

        throw new ConnectionException($lastError ? (string) $lastError : 'Request failed');
    }

    /**
     * @param string[] $headers
     * @return array{0:int,1:string,2:?string,3:?string}
     */
    private function send(string $method, string $url, array $headers, ?string $payload): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [0, '', null, $err ?: 'unknown cURL error'];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr((string) $response, 0, $headerSize);
        $rawBody = substr((string) $response, $headerSize);
        $requestId = $this->extractRequestId($rawHeaders);

        return [$status, $rawBody, $requestId, null];
    }

    private function extractRequestId(string $rawHeaders): ?string
    {
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, 'x-request-id:') === 0) {
                return trim(substr($line, strlen('x-request-id:')));
            }
        }
        return null;
    }

    /** @return mixed */
    private function safeJson(string $raw)
    {
        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    private function backoff(int $attempt): float
    {
        $base = min(0.5 * (2 ** $attempt), 10.0);
        return $base + $base * 0.2 * (mt_rand() / mt_getrandmax());
    }
}
