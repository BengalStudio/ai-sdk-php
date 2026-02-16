<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Types;

use BengalStudio\AI\Types\LanguageModelGenerateResult;
use BengalStudio\AI\Types\LanguageModelUsage;
use PHPUnit\Framework\TestCase;

class LanguageModelGenerateResultTest extends TestCase
{
    public function testGetText(): void
    {
        $result = new LanguageModelGenerateResult(
            content: [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'text', 'text' => 'world!'],
            ],
            finishReason: 'stop',
            usage: new LanguageModelUsage(inputTokens: 10, outputTokens: 5),
        );

        $this->assertSame('Hello world!', $result->getText());
    }

    public function testGetTextWithNoTextParts(): void
    {
        $result = new LanguageModelGenerateResult(
            content: [
                ['type' => 'tool-call', 'toolName' => 'weather', 'input' => '{}'],
            ],
            finishReason: 'tool-calls',
            usage: new LanguageModelUsage(),
        );

        $this->assertSame('', $result->getText());
    }

    public function testGetToolCalls(): void
    {
        $result = new LanguageModelGenerateResult(
            content: [
                ['type' => 'text', 'text' => 'Let me check...'],
                ['type' => 'tool-call', 'toolCallId' => 'call_1', 'toolName' => 'weather', 'input' => '{"city":"SF"}'],
                ['type' => 'tool-call', 'toolCallId' => 'call_2', 'toolName' => 'time', 'input' => '{}'],
            ],
            finishReason: 'tool-calls',
            usage: new LanguageModelUsage(),
        );

        $toolCalls = $result->getToolCalls();
        $this->assertCount(2, $toolCalls);
        $this->assertSame('weather', $toolCalls[0]['toolName']);
        $this->assertSame('time', $toolCalls[1]['toolName']);
    }

    public function testPropertiesPreserved(): void
    {
        $usage = new LanguageModelUsage(inputTokens: 100, outputTokens: 50);
        $result = new LanguageModelGenerateResult(
            content: [['type' => 'text', 'text' => 'test']],
            finishReason: 'stop',
            usage: $usage,
            warnings: [['type' => 'unsupported', 'feature' => 'topK']],
            providerMetadata: ['openai' => ['responseId' => 'resp_123']],
            request: ['body' => ['model' => 'gpt-4']],
        );

        $this->assertSame('stop', $result->finishReason);
        $this->assertSame($usage, $result->usage);
        $this->assertNotNull($result->warnings);
        $this->assertNotNull($result->providerMetadata);
        $this->assertSame('resp_123', $result->providerMetadata['openai']['responseId']);
    }
}
