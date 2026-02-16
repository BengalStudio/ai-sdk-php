<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Core;

use BengalStudio\AI\Core\StepResult;
use BengalStudio\AI\Types\FinishReason;
use BengalStudio\AI\Types\LanguageModelResponseMetadata;
use BengalStudio\AI\Types\LanguageModelUsage;
use PHPUnit\Framework\TestCase;

class StepResultTest extends TestCase
{
    public function testBasicConstruction(): void
    {
        $step = new StepResult(
            text: 'Hello world',
            toolCalls: [],
            toolResults: [],
            finishReason: FinishReason::Stop,
            usage: new LanguageModelUsage(inputTokens: 10, outputTokens: 5),
            warnings: [],
            response: null,
        );

        $this->assertSame('Hello world', $step->text);
        $this->assertSame(FinishReason::Stop, $step->finishReason);
        $this->assertSame(10, $step->usage->inputTokens);
        $this->assertFalse($step->hasToolCalls());
    }

    public function testHasToolCalls(): void
    {
        $toolCall = new \BengalStudio\AI\Tool\ToolCall(
            toolCallId: 'call_1',
            toolName: 'search',
            input: ['q' => 'test'],
        );

        $step = new StepResult(
            text: '',
            toolCalls: [$toolCall],
            toolResults: [],
            finishReason: FinishReason::ToolCalls,
            usage: new LanguageModelUsage(),
            warnings: [],
            response: null,
        );

        $this->assertTrue($step->hasToolCalls());
    }

    public function testToArray(): void
    {
        $step = new StepResult(
            text: 'test',
            toolCalls: [],
            toolResults: [],
            finishReason: FinishReason::Stop,
            usage: new LanguageModelUsage(inputTokens: 5, outputTokens: 3),
            warnings: [],
            response: null,
        );

        $arr = $step->toArray();
        $this->assertSame('test', $arr['text']);
        $this->assertSame('stop', $arr['finishReason']);
        $this->assertEmpty($arr['toolCalls']);
    }

    public function testContentField(): void
    {
        $content = [
            ['type' => 'text', 'text' => 'Hello'],
            ['type' => 'source', 'url' => 'https://example.com'],
        ];

        $step = new StepResult(
            text: 'Hello',
            toolCalls: [],
            toolResults: [],
            finishReason: FinishReason::Stop,
            usage: new LanguageModelUsage(),
            warnings: [],
            response: null,
            content: $content,
        );

        $this->assertCount(2, $step->content);
        $this->assertSame('text', $step->content[0]['type']);
    }
}
