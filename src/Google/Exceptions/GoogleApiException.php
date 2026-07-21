<?php

declare(strict_types=1);

namespace Goletter\Docs\Google\Exceptions;

class GoogleApiException extends \RuntimeException
{
    protected array $response;

    public function __construct(
        string $message = '',
        int $code = 0,
        array $response = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
    }

    public function getResponse(): array
    {
        return $this->response;
    }
}
