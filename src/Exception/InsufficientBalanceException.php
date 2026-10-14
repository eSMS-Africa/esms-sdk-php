<?php

declare(strict_types=1);

namespace Esms\Exception;

/**
 * 402 "insufficient_balance" - not enough credit to send.
 * Exposes the shortfall so callers can prompt a top-up.
 */
class InsufficientBalanceException extends InvalidRequestException
{
    /** @return float|null */
    public function getBalance()
    {
        // Some paths report "available"/"required" instead of "balance"/"cost".
        return is_array($this->detail) ? ($this->detail['balance'] ?? $this->detail['available'] ?? null) : null;
    }

    /** @return float|null */
    public function getCost()
    {
        return is_array($this->detail) ? ($this->detail['cost'] ?? $this->detail['required'] ?? null) : null;
    }

    public function getCurrency(): ?string
    {
        if (is_array($this->detail) && is_string($this->detail['currency'] ?? null)) {
            return $this->detail['currency'];
        }
        // The API states the wallet currency only inside the message
        // ("Required KES 5, available KES 1"), so recover it from there.
        $text = is_array($this->detail) && is_string($this->detail['message'] ?? null)
            ? $this->detail['message']
            : $this->getMessage();
        if (preg_match('/\b(?:Required|available)\s+([A-Z]{3})\b/', $text, $m)) {
            return $m[1];
        }
        return null;
    }
}
