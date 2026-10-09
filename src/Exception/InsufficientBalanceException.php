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
        return is_array($this->detail) ? ($this->detail['currency'] ?? null) : null;
    }
}
