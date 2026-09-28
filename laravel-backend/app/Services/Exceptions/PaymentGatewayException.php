<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Domain exception carrying an HTTP status and a FastAPI-compatible detail message.
 * Rendered by the exception handler as {"detail": "..."} with the given status code.
 */
class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 502
    ) {
        parent::__construct($message);
    }
}
