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
    private const VERSION = '1.2.0';

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
            if ($payload === false) {
                // e.g. text that is not valid UTF-8: fail loudly instead of
                // POSTing an empty body.
                throw new \InvalidArgumentException('Could not encode the request body as JSON: ' . json_last_error_msg());
            }
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
            [$status, $rawBody, $requestId, $curlErr, $retryAfter] = $this->send($method, $url, $headers, $payload);

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
                if (is_string($parsed)) {
                    // A 2xx that is not JSON (e.g. an HTML page from a proxy).
                    throw new EsmsException("Unexpected non-JSON response (HTTP {$status})", $status, null, substr($parsed, 0, 500), $requestId);
                }
                return $parsed;
            }

            if (($status === 429 || ($status >= 500 && $retrySafe)) && $attempt < $this->maxRetries) {
                $lastError = "HTTP {$status}";
                usleep((int) ($this->backoff($attempt, $retryAfter) * 1_000_000));
                continue;
            }

            throw EsmsException::fromResponse($status, $parsed, $requestId);
        }

        throw new ConnectionException($lastError ? (string) $lastError : 'Request failed');
    }

    /**
     * Send a raw body (multipart uploads). Not retried after a 5xx or network
     * error (a POST without an Idempotency-Key); 429 is retried.
     *
     * @return mixed
     */
    public function requestRaw(string $method, string $path, string $payload, string $contentType)
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: esms-php/' . self::VERSION,
            'Content-Type: ' . $contentType,
        ];
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            [$status, $rawBody, $requestId, $curlErr, $retryAfter] = $this->send($method, $url, $headers, $payload);
            if ($curlErr !== null) {
                throw new ConnectionException("Could not reach the eSMS API: {$curlErr}");
            }
            $parsed = $rawBody === '' ? null : $this->safeJson($rawBody);
            if ($status >= 200 && $status < 300) {
                if (is_string($parsed)) {
                    throw new EsmsException("Unexpected non-JSON response (HTTP {$status})", $status, null, substr($parsed, 0, 500), $requestId);
                }
                return $parsed;
            }
            if ($status === 429 && $attempt < $this->maxRetries) {
                usleep((int) ($this->backoff($attempt, $retryAfter) * 1_000_000));
                continue;
            }
            throw EsmsException::fromResponse($status, $parsed, $requestId);
        }
        throw new ConnectionException('Request failed');
    }

    /**
     * @param string[] $headers
     * @return array{0:int,1:string,2:?string,3:?string,4:?string}
     */
    protected function send(string $method, string $url, array $headers, ?string $payload): array
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
            // Never follow redirects (the bearer key would go with them), only
            // speak http(s), and always verify TLS whatever php.ini says.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [0, '', null, $err ?: 'unknown cURL error', null];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr((string) $response, 0, $headerSize);
        $rawBody = substr((string) $response, $headerSize);
        $requestId = $this->extractHeader($rawHeaders, 'x-request-id');
        $retryAfter = $this->extractHeader($rawHeaders, 'retry-after');

        return [$status, $rawBody, $requestId, null, $retryAfter];
    }

    /**
     * Last value of a response header (the final response's block wins when
     * cURL also captured an interim 100 Continue or a proxy CONNECT block).
     */
    private function extractHeader(string $rawHeaders, string $name): ?string
    {
        $value = null;
        $prefix = strtolower($name) . ':';
        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            if (stripos($line, $prefix) === 0) {
                $value = trim(substr($line, strlen($prefix)));
            }
        }
        return $value;
    }

    /** @return mixed */
    private function safeJson(string $raw)
    {
        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    /**
     * Seconds to wait before the next attempt. A numeric Retry-After header
     * wins (capped at 20s, like the other SDKs).
     */
    public function backoff(int $attempt, ?string $retryAfter = null): float
    {
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return min((float) $retryAfter, 20.0);
        }
        $base = min(0.5 * (2 ** $attempt), 10.0);
        return $base + $base * 0.2 * (mt_rand() / mt_getrandmax());
    }
}
