<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Prompt;

use BengalStudio\AI\Prompt\UIMessage;
use PHPUnit\Framework\TestCase;

class UIMessageTest extends TestCase
{
    public function testFromArray(): void
    {
        $msg = UIMessage::fromArray([
            'id' => 'msg_1',
            'role' => 'user',
            'parts' => [
                ['type' => 'text', 'text' => 'Hello world'],
            ],
            'metadata' => ['foo' => 'bar'],
        ]);

        $this->assertSame('msg_1', $msg->id);
        $this->assertSame('user', $msg->role);
        $this->assertCount(1, $msg->parts);
        $this->assertSame(['foo' => 'bar'], $msg->metadata);
    }

    public function testFromArrayDefaults(): void
    {
        $msg = UIMessage::fromArray([]);

        $this->assertSame('', $msg->id);
        $this->assertSame('user', $msg->role);
        $this->assertEmpty($msg->parts);
        $this->assertEmpty($msg->metadata);
    }

    public function testGetPartsByType(): void
    {
        $msg = UIMessage::fromArray([
            'id' => 'msg_1',
            'role' => 'assistant',
            'parts' => [
                ['type' => 'text', 'text' => 'Hello'],
                ['type' => 'tool-weather', 'toolCallId' => 'call_1', 'state' => 'output-available', 'input' => [], 'output' => 'sunny'],
                ['type' => 'text', 'text' => ' world'],
            ],
        ]);

        $textParts = $msg->getPartsByType('text');
        $this->assertCount(2, $textParts);
        $this->assertSame('Hello', $textParts[0]['text']);
        $this->assertSame(' world', $textParts[1]['text']);

        $toolParts = $msg->getPartsByType('tool-weather');
        $this->assertCount(1, $toolParts);
        $this->assertSame('call_1', $toolParts[0]['toolCallId']);
    }

    public function testGetTextContent(): void
    {
        $msg = UIMessage::fromArray([
            'id' => 'msg_1',
            'role' => 'user',
            'parts' => [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'file', 'url' => 'data:image/png;base64,...', 'mediaType' => 'image/png'],
                ['type' => 'text', 'text' => 'world'],
            ],
        ]);

        $this->assertSame('Hello world', $msg->getTextContent());
    }

    public function testGetToolInvocations(): void
    {
        $msg = UIMessage::fromArray([
            'id' => 'msg_2',
            'role' => 'assistant',
            'parts' => [
                ['type' => 'text', 'text' => 'Let me check...'],
                [
                    'type' => 'tool-getWeather',
                    'toolCallId' => 'call_1',
                    'state' => 'output-available',
                    'input' => ['city' => 'SF'],
                    'output' => ['temp' => 72],
                ],
                [
                    'type' => 'dynamic-tool',
                    'toolName' => 'lookup',
                    'toolCallId' => 'call_2',
                    'state' => 'output-available',
                    'input' => [],
                    'output' => 'ok',
                ],
            ],
        ]);

        $tools = $msg->getToolInvocations();
        $this->assertCount(2, $tools);
        $this->assertSame('tool-getWeather', $tools[0]['type']);
        $this->assertSame('output-available', $tools[0]['state']);
        $this->assertSame('dynamic-tool', $tools[1]['type']);
        $this->assertSame('lookup', $tools[1]['toolName']);
    }
}
