<?php

declare(strict_types=1);

namespace BengalStudio\AI\Types;

/**
 * Result of a language model stream call.
 */
class LanguageModelStreamResult
{
    /**
     * @param \Generator $stream The streaming generator yielding stream parts.
     */
    public function __construct(
        public readonly \Generator $stream,
    ) {}

    /**
     * Get the underlying stream generator.
     */
    public function getStream(): \Generator
    {
        return $this->stream;
    }
}
