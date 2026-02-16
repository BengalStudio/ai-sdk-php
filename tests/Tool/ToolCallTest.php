<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Tool;

use BengalStudio\AI\Tool\ToolCall;
use PHPUnit\Framework\TestCase;

class ToolCallTest extends TestCase
{
    public function testConstructor(): void
    {
        $tc = new ToolCall(
            toolCallId: 'call_abc',
            toolName: 'weather',
            input: ['city' => 'NY'],
        );

        $this->assertSame('call_abc', $tc->toolCallId);
        $this->assertSame('weather', $tc->toolName);
        $this->assertSame(['city' => 'NY'], $tc->input);
        $this->assertSame('function', $tc->toolCallType);
    }

    public function testFromContentPartWithArray(): void
    {
        $tc = ToolCall::fromContentPart([
            'toolCallId' => 'call_1',
            'toolName' => 'search',
            'input' => ['q' => 'php'],
        ]);

        $this->assertSame('call_1', $tc->toolCallId);
        $this->assertSame('search', $tc->toolName);
        $this->assertSame(['q' => 'php'], $tc->input);
    }

    public function testFromContentPartWithJsonStringInput(): void
    {
        $tc = ToolCall::fromContentPart([
            'toolCallId' => 'call_2',
            'toolName' => 'weather',
            'input' => '{"city":"SF"}',
        ]);

        $this->assertSame(['city' => 'SF'], $tc->input);
    }

    public function testFromContentPartDefaults(): void
    {
        $tc = ToolCall::fromContentPart([]);

        $this->assertSame('', $tc->toolCallId);
        $this->assertSame('', $tc->toolName);
        $this->assertSame([], $tc->input);
    }

    public function testToArray(): void
    {
        $tc = new ToolCall(
            toolCallId: 'call_1',
            toolName: 'test',
            input: ['key' => 'value'],
        );

        $arr = $tc->toArray();
        $this->assertSame('tool-call', $arr['type']);
        $this->assertSame('call_1', $arr['toolCallId']);
        $this->assertSame('test', $arr['toolName']);
        $this->assertSame(['key' => 'value'], $arr['input']);
    }
}
