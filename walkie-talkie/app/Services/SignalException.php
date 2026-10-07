<?php
declare(strict_types=1);

namespace App\Services;

/** An expected signaling error that is reported to the browser as JSON. */
final class SignalException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}
