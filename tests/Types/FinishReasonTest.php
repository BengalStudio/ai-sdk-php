<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Types;

use BengalStudio\AI\Types\FinishReason;
use PHPUnit\Framework\TestCase;

class FinishReasonTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('stop', FinishReason::Stop->value);
        $this->assertSame('length', FinishReason::Length->value);
        $this->assertSame('tool-calls', FinishReason::ToolCalls->value);
        $this->assertSame('content-filter', FinishReason::ContentFilter->value);
        $this->assertSame('error', FinishReason::Error->value);
        $this->assertSame('other', FinishReason::Other->value);
        $this->assertSame('unknown', FinishReason::Unknown->value);
    }

    public function testTryFromValidValues(): void
    {
        $this->assertSame(FinishReason::Stop, FinishReason::tryFrom('stop'));
        $this->assertSame(FinishReason::Length, FinishReason::tryFrom('length'));
        $this->assertSame(FinishReason::ToolCalls, FinishReason::tryFrom('tool-calls'));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        $this->assertNull(FinishReason::tryFrom('nonexistent'));
    }

    public function testFromThrowsOnInvalid(): void
    {
        $this->expectException(\ValueError::class);
        FinishReason::from('nonexistent');
    }

    public function testAllCasesExist(): void
    {
        $cases = FinishReason::cases();
        $this->assertCount(7, $cases);
    }
}
