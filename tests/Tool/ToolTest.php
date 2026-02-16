<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Tool;

use BengalStudio\AI\Tool\Tool;
use PHPUnit\Framework\TestCase;

class ToolTest extends TestCase
{
    public function testDefine(): void
    {
        $tool = Tool::define(
            description: 'Get weather info',
            inputSchema: [
                'type' => 'object',
                'properties' => ['location' => ['type' => 'string']],
                'required' => ['location'],
            ],
            execute: fn(array $input) => ['temp' => 72],
        );

        $this->assertSame('Get weather info', $tool->description);
        $this->assertSame('function', $tool->type);
        $this->assertNotNull($tool->inputSchema);
        $this->assertNotNull($tool->execute);
    }

    public function testInvoke(): void
    {
        $tool = Tool::define(
            execute: fn(array $input) => ['result' => $input['x'] * 2],
        );

        $result = $tool(['x' => 5]);
        $this->assertSame(['result' => 10], $result);
    }

    public function testInvokeWithoutExecuteReturnsNull(): void
    {
        $tool = new Tool(description: 'No-op tool');
        $result = $tool([]);
        $this->assertNull($result);
    }

    public function testToLanguageModelTool(): void
    {
        $tool = Tool::define(
            description: 'Test tool',
            inputSchema: ['type' => 'object', 'properties' => []],
        );

        $lmTool = $tool->toLanguageModelTool('myTool');

        $this->assertSame('function', $lmTool['type']);
        $this->assertSame('myTool', $lmTool['name']);
        $this->assertSame('Test tool', $lmTool['description']);
        $this->assertArrayHasKey('inputSchema', $lmTool);
    }
}
