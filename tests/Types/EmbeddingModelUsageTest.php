<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Types;

use BengalStudio\AI\Types\EmbeddingModelUsage;
use PHPUnit\Framework\TestCase;

class EmbeddingModelUsageTest extends TestCase
{
    public function testDefault(): void
    {
        $usage = new EmbeddingModelUsage();
        $this->assertSame(0, $usage->tokens);
    }

    public function testCustomValue(): void
    {
        $usage = new EmbeddingModelUsage(tokens: 500);
        $this->assertSame(500, $usage->tokens);
    }

    public function testAdd(): void
    {
        $a = new EmbeddingModelUsage(tokens: 100);
        $b = new EmbeddingModelUsage(tokens: 200);
        $sum = $a->add($b);

        $this->assertSame(300, $sum->tokens);
    }

    public function testToArray(): void
    {
        $usage = new EmbeddingModelUsage(tokens: 150);
        $this->assertSame(['tokens' => 150], $usage->toArray());
    }
}
