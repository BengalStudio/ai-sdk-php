<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Tool;

use BengalStudio\AI\Tool\ToolResult;
use PHPUnit\Framework\TestCase;

class ToolResultTest extends TestCase
{
    public function testConstructor(): void
    {
        $tr = new ToolResult(
            toolCallId: 'call_1',
            toolName: 'weather',
            input: ['city' => 'SF'],
            output: ['temp' => 72],
        );

        $this->assertSame('call_1', $tr->toolCallId);
        $this->assertSame('weather', $tr->toolName);
        $this->assertSame(['city' => 'SF'], $tr->input);
        $this->assertSame(['temp' => 72], $tr->output);
    }

    public function testToArray(): void
    {
        $tr = new ToolResult(
            toolCallId: 'call_1',
            toolName: 'test',
            input: ['a' => 1],
            output: 'result text',
        );

        $arr = $tr->toArray();
        $this->assertSame('tool-result', $arr['type']);
        $this->assertSame('call_1', $arr['toolCallId']);
        $this->assertSame('test', $arr['toolName']);
        $this->assertSame('result text', $arr['output']);
    }

    public function testToMessagePart(): void
    {
        $tr = new ToolResult(
            toolCallId: 'call_1',
            toolName: 'weather',
            input: [],
            output: ['temp' => 72],
        );

        $part = $tr->toMessagePart();
        $this->assertSame('tool-result', $part['type']);
        $this->assertSame('call_1', $part['toolCallId']);
        $this->assertSame('{"temp":72}', $part['result']);
    }

    public function testToMessagePartWithStringOutput(): void
    {
        $tr = new ToolResult(
            toolCallId: 'call_1',
            toolName: 'echo',
            input: [],
            output: 'hello world',
        );

        $part = $tr->toMessagePart();
        $this->assertSame('hello world', $part['result']);
    }
}
