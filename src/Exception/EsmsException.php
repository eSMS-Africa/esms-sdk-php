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
     * @param mixed $body
     */
    public static function fromResponse(int $status, $body, ?string $requestId = null): EsmsException
    {
        $detail = $body;
        if (is_array($body) && array_key_exists('detail', $body)) {
            $detail = $body['detail'];
        }

        $code = null;
        $message = null;
        if (is_array($detail)) {
            $code = $detail['code'] ?? null;
            $message = $detail['message'] ?? null;
        } elseif (is_string($detail)) {
            $message = $detail;
        }
        if ($message === null || $message === '') {
            $message = "HTTP {$status}";
        }

        if ($code === 'insufficient_balance') {
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
}
