<?php

declare(strict_types=1);

namespace Esms\Exception;

/** 429 - too many requests; back off and retry later. */
class RateLimitException extends EsmsException
{
}
