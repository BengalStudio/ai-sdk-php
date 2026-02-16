<?php

declare(strict_types=1);

namespace BengalStudio\AI\Exceptions;

/**
 * Thrown when a provider is not found.
 */
class NoSuchProviderException extends AIException
{
    public function __construct(
        public readonly string $providerId,
    ) {
        parent::__construct("No such provider: {$providerId}");
    }
}
