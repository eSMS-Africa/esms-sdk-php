<?php

declare(strict_types=1);

namespace Esms\Exception;

/**
 * 422 "insufficient_balance" - not enough credit to send.
 * Exposes the shortfall so callers can prompt a top-up.
 */
class InsufficientBalanceException extends InvalidRequestException
{
    /** @return float|null */
    public function getBalance()
    {
        return is_array($this->detail) ? ($this->detail['balance'] ?? null) : null;
    }

    /** @return float|null */
    public function getCost()
    {
        return is_array($this->detail) ? ($this->detail['cost'] ?? null) : null;
    }

    public function getCurrency(): ?string
    {
        return is_array($this->detail) ? ($this->detail['currency'] ?? null) : null;
    }
}
