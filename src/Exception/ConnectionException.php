<?php

declare(strict_types=1);

namespace Esms\Exception;

/** The request never reached the API (DNS, TLS, timeout, offline). */
class ConnectionException extends EsmsException
{
}
