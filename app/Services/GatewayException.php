<?php

namespace App\Services;

use RuntimeException;

/** A payment gateway request that failed (bad request, auth, gateway down). */
class GatewayException extends RuntimeException
{
    public function __construct(string $message, int $status = 0, public readonly ?string $errorCode = null)
    {
        parent::__construct($message, $status);
    }
}
