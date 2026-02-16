<?php

declare(strict_types=1);

namespace BengalStudio\AI\Util;

/**
 * Utility for generating unique IDs.
 */
class IdGenerator
{
    private string $prefix;
    private int $size;

    public function __construct(string $prefix = 'ai', int $size = 24)
    {
        $this->prefix = $prefix;
        $this->size = $size;
    }

    /**
     * Generate a unique ID.
     */
    public function generate(): string
    {
        $bytes = random_bytes((int) ceil($this->size / 2));
        $hex = substr(bin2hex($bytes), 0, $this->size);
        return $this->prefix . '-' . $hex;
    }

    /**
     * Static helper to generate an ID.
     */
    public static function createId(string $prefix = 'ai', int $size = 24): string
    {
        return (new self($prefix, $size))->generate();
    }
}
