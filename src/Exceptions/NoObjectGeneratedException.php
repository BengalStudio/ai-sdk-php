<?php

declare(strict_types=1);

namespace BengalStudio\AI\Exceptions;

/**
 * Thrown when structured object generation fails.
 */
class NoObjectGeneratedException extends AIException
{
    public function __construct(
        public readonly string $text = '',
        public readonly ?string $reason = null,
    ) {
        $message = 'No object generated.';
        if ($reason) {
            $message .= " Reason: {$reason}";
        }
        parent::__construct($message);
    }
}
