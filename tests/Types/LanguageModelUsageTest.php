<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Types;

use BengalStudio\AI\Types\LanguageModelUsage;
use PHPUnit\Framework\TestCase;

class LanguageModelUsageTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $usage = new LanguageModelUsage();

        $this->assertSame(0, $usage->inputTokens);
        $this->assertSame(0, $usage->outputTokens);
        $this->assertNull($usage->totalTokens);
        $this->assertNull($usage->reasoningTokens);
        $this->assertNull($usage->cachedInputTokens);
    }

    public function testCustomValues(): void
    {
        $usage = new LanguageModelUsage(
            inputTokens: 100,
            outputTokens: 50,
            totalTokens: 150,
            reasoningTokens: 20,
            cachedInputTokens: 30,
        );

        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(50, $usage->outputTokens);
        $this->assertSame(150, $usage->totalTokens);
        $this->assertSame(20, $usage->reasoningTokens);
        $this->assertSame(30, $usage->cachedInputTokens);
    }

    public function testTotalCalculatedWhenNotProvided(): void
    {
        $usage = new LanguageModelUsage(inputTokens: 100, outputTokens: 50);
        $this->assertSame(150, $usage->total());
    }

    public function testTotalUsesExplicitValueWhenProvided(): void
    {
        $usage = new LanguageModelUsage(inputTokens: 100, outputTokens: 50, totalTokens: 200);
        $this->assertSame(200, $usage->total());
    }

    public function testAdd(): void
    {
        $a = new LanguageModelUsage(inputTokens: 100, outputTokens: 50);
        $b = new LanguageModelUsage(inputTokens: 200, outputTokens: 100, reasoningTokens: 10);

        $sum = $a->add($b);

        $this->assertSame(300, $sum->inputTokens);
        $this->assertSame(150, $sum->outputTokens);
        $this->assertSame(450, $sum->totalTokens); // 150 + 300
        $this->assertSame(10, $sum->reasoningTokens);
    }

    public function testAddWithCachedTokens(): void
    {
        $a = new LanguageModelUsage(inputTokens: 100, outputTokens: 50, cachedInputTokens: 20);
        $b = new LanguageModelUsage(inputTokens: 100, outputTokens: 50, cachedInputTokens: 30);

        $sum = $a->add($b);
        $this->assertSame(50, $sum->cachedInputTokens);
    }

    public function testToArray(): void
    {
        $usage = new LanguageModelUsage(inputTokens: 100, outputTokens: 50);
        $arr = $usage->toArray();

        $this->assertSame(100, $arr['inputTokens']);
        $this->assertSame(50, $arr['outputTokens']);
        $this->assertSame(150, $arr['totalTokens']);
        $this->assertArrayNotHasKey('reasoningTokens', $arr);
        $this->assertArrayNotHasKey('cachedInputTokens', $arr);
    }

    public function testToArrayWithAllFields(): void
    {
        $usage = new LanguageModelUsage(
            inputTokens: 100,
            outputTokens: 50,
            reasoningTokens: 20,
            cachedInputTokens: 30,
        );
        $arr = $usage->toArray();

        $this->assertSame(20, $arr['reasoningTokens']);
        $this->assertSame(30, $arr['cachedInputTokens']);
    }
}
