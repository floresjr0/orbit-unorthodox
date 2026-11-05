<?php

declare(strict_types=1);

namespace Orbit;

final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly mixed $data = null
    ) {
        parent::__construct($message);
    }
}
