<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Tool;

use BengalStudio\AI\Tool\Tool;
use BengalStudio\AI\Tool\ToolCall;
use BengalStudio\AI\Tool\ToolPreparer;
use PHPUnit\Framework\TestCase;

class ToolPreparerTest extends TestCase
{
    public function testPrepareWithNullTools(): void
    {
        $result = ToolPreparer::prepare(null);

        $this->assertNull($result['tools']);
        $this->assertNull($result['toolChoice']);
    }

    public function testPrepareWithEmptyTools(): void
    {
        $result = ToolPreparer::prepare([]);

        $this->assertNull($result['tools']);
        $this->assertNull($result['toolChoice']);
    }

    public function testPrepareWithTools(): void
    {
        $tools = [
            'weather' => Tool::define(
                description: 'Get weather',
                inputSchema: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            ),
        ];

        $result = ToolPreparer::prepare($tools);

        $this->assertCount(1, $result['tools']);
        $this->assertSame('weather', $result['tools'][0]['name']);
        $this->assertSame('function', $result['tools'][0]['type']);
        $this->assertSame(['type' => 'auto'], $result['toolChoice']);
    }

    public function testPrepareWithStringToolChoice(): void
    {
        $tools = ['t' => Tool::define(description: 'test')];
        $result = ToolPreparer::prepare($tools, 'required');

        $this->assertSame(['type' => 'required'], $result['toolChoice']);
    }

    public function testPrepareWithArrayToolChoice(): void
    {
        $tools = ['t' => Tool::define(description: 'test')];
        $choice = ['type' => 'tool', 'toolName' => 't'];
        $result = ToolPreparer::prepare($tools, $choice);

        $this->assertSame($choice, $result['toolChoice']);
    }

    public function testExecuteToolCall(): void
    {
        $tools = [
            'double' => Tool::define(
                execute: fn(array $input) => $input['x'] * 2,
            ),
        ];

        $toolCall = new ToolCall(
            toolCallId: 'call_1',
            toolName: 'double',
            input: ['x' => 5],
        );

        $result = ToolPreparer::executeToolCall($toolCall, $tools);

        $this->assertNotNull($result);
        $this->assertSame('call_1', $result->toolCallId);
        $this->assertSame('double', $result->toolName);
        $this->assertSame(10, $result->output);
    }

    public function testExecuteToolCallWithMissingTool(): void
    {
        $result = ToolPreparer::executeToolCall(
            new ToolCall('call_1', 'nonexistent', []),
            [],
        );

        $this->assertNull($result);
    }

    public function testExecuteToolCallWithNoExecute(): void
    {
        $tools = ['noop' => new Tool(description: 'No execute')];

        $result = ToolPreparer::executeToolCall(
            new ToolCall('call_1', 'noop', []),
            $tools,
        );

        $this->assertNull($result);
    }
}
