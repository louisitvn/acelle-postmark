<?php

namespace Acelle\Plugin\Postmark\Postmark;

class Error extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 0, public readonly int $errorCode = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
