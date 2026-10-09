<?php

declare(strict_types=1);

namespace Esms\Exception;

/**
 * Base class for every error thrown by the eSMS SDK.
 *
 * Inspect getStatus() and getCode() to branch, or catch a specific subclass.
 */
class EsmsException extends \Exception
{
    /** @var int|null HTTP status code, when the failure came from the API. */
    protected $status;

    /** @var string|null Machine-readable error code (e.g. "insufficient_balance"). */
    protected $apiCode;

    /** @var mixed Raw "detail" payload returned by the API. */
    protected $detail;

    /** @var string|null The X-Request-Id response header. */
    protected $requestId;

    /**
     * @param mixed $detail
     */
    public function __construct(
        string $message,
        ?int $status = null,
        ?string $apiCode = null,
        $detail = null,
        ?string $requestId = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
        $this->apiCode = $apiCode;
        $this->detail = $detail;
        $this->requestId = $requestId;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function getApiCode(): ?string
    {
        return $this->apiCode;
    }

    /** @return mixed */
    public function getDetail()
    {
        return $this->detail;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Build the right exception subclass from an HTTP status and parsed body.
     *
     * The API returns {"detail": <string|object|list>, "error": {code, message,
     * request_id}}; the rate limiter returns {"error": "<text>"}.
     *
     * @param mixed $body
     */
    public static function fromResponse(int $status, $body, ?string $requestId = null): EsmsException
    {
        $isObj = is_array($body) && !self::isList($body);
        $detail = ($isObj && array_key_exists('detail', $body)) ? $body['detail'] : $body;
        $envelope = ($isObj && isset($body['error']) && is_array($body['error'])) ? $body['error'] : null;

        $code = null;
        $message = null;
        if (is_array($detail) && !self::isList($detail)) {
            $code = is_string($detail['code'] ?? null) ? $detail['code'] : null;
            $message = is_string($detail['message'] ?? null) ? $detail['message'] : null;
        } elseif (is_string($detail) && $detail !== '') {
            $message = $detail;
        } elseif (is_array($detail) && isset($detail[0]['msg']) && is_string($detail[0]['msg'])) {
            // 422 validation errors: [{"loc": [...], "msg": "..."}]
            $loc = isset($detail[0]['loc']) && is_array($detail[0]['loc']) ? implode('.', $detail[0]['loc']) : '';
            $message = 'Request validation failed: ' . ($loc !== '' ? $loc . ': ' : '') . $detail[0]['msg'];
        }
        if ($envelope !== null) {
            if ($code === null && is_string($envelope['code'] ?? null)) {
                $code = $envelope['code'];
            }
            if (($message === null || $message === '') && is_string($envelope['message'] ?? null)) {
                $message = $envelope['message'];
            }
            if (($requestId === null || $requestId === '') && is_string($envelope['request_id'] ?? null)) {
                $requestId = $envelope['request_id'];
            }
        } elseif ($isObj && is_string($body['error'] ?? null) && ($message === null || $message === '')) {
            $message = $body['error'];
        }
        if ($message === null || $message === '') {
            $message = "HTTP {$status}";
        }
        if ($requestId === '') {
            $requestId = null;
        }

        if ($code === 'insufficient_balance' || $status === 402) {
            return new InsufficientBalanceException($message, $status, $code, $detail, $requestId);
        }

        switch ($status) {
            case 401:
                return new AuthenticationException($message, $status, $code, $detail, $requestId);
            case 403:
                return new PermissionException($message, $status, $code, $detail, $requestId);
            case 404:
                return new NotFoundException($message, $status, $code, $detail, $requestId);
            case 400:
            case 409:
            case 413:
            case 422:
                return new InvalidRequestException($message, $status, $code, $detail, $requestId);
            case 429:
                return new RateLimitException($message, $status, $code, $detail, $requestId);
            default:
                if ($status >= 500) {
                    return new ApiException($message, $status, $code, $detail, $requestId);
                }
                return new EsmsException($message, $status, $code, $detail, $requestId);
        }
    }

    /**
     * array_is_list() for PHP < 8.1.
     *
     * @param array<mixed> $a
     */
    private static function isList(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }
}
