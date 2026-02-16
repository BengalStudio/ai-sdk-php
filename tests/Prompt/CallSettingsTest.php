<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Prompt;

use BengalStudio\AI\Prompt\CallSettings;
use PHPUnit\Framework\TestCase;

class CallSettingsTest extends TestCase
{
    public function testEmptySettingsReturnsEmpty(): void
    {
        $this->assertSame([], CallSettings::prepare([]));
    }

    public function testValidMaxOutputTokens(): void
    {
        $result = CallSettings::prepare(['maxOutputTokens' => 100]);
        $this->assertSame(100, $result['maxOutputTokens']);
    }

    public function testInvalidMaxOutputTokensThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxOutputTokens must be >= 1');
        CallSettings::prepare(['maxOutputTokens' => 0]);
    }

    public function testValidTemperature(): void
    {
        $result = CallSettings::prepare(['temperature' => 0.7]);
        $this->assertSame(0.7, $result['temperature']);
    }

    public function testNegativeTemperatureThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CallSettings::prepare(['temperature' => -0.1]);
    }

    public function testValidTopP(): void
    {
        $result = CallSettings::prepare(['topP' => 0.9]);
        $this->assertSame(0.9, $result['topP']);
    }

    public function testTopPOutOfRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CallSettings::prepare(['topP' => 1.5]);
    }

    public function testValidTopK(): void
    {
        $result = CallSettings::prepare(['topK' => 40]);
        $this->assertSame(40, $result['topK']);
    }

    public function testInvalidTopKThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CallSettings::prepare(['topK' => 0]);
    }

    public function testValidFrequencyPenalty(): void
    {
        $result = CallSettings::prepare(['frequencyPenalty' => 0.5]);
        $this->assertSame(0.5, $result['frequencyPenalty']);
    }

    public function testFrequencyPenaltyOutOfRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CallSettings::prepare(['frequencyPenalty' => 3.0]);
    }

    public function testValidPresencePenalty(): void
    {
        $result = CallSettings::prepare(['presencePenalty' => -1.0]);
        $this->assertSame(-1.0, $result['presencePenalty']);
    }

    public function testPresencePenaltyOutOfRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CallSettings::prepare(['presencePenalty' => -2.5]);
    }

    public function testStopSequences(): void
    {
        $result = CallSettings::prepare(['stopSequences' => ['END', 'STOP']]);
        $this->assertSame(['END', 'STOP'], $result['stopSequences']);
    }

    public function testSeed(): void
    {
        $result = CallSettings::prepare(['seed' => 42]);
        $this->assertSame(42, $result['seed']);
    }

    public function testMultipleSettings(): void
    {
        $result = CallSettings::prepare([
            'maxOutputTokens' => 500,
            'temperature' => 0.8,
            'topP' => 0.95,
            'seed' => 123,
        ]);

        $this->assertSame(500, $result['maxOutputTokens']);
        $this->assertSame(0.8, $result['temperature']);
        $this->assertSame(0.95, $result['topP']);
        $this->assertSame(123, $result['seed']);
    }
}
