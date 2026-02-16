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
                ['type' => 'tool-invocation', 'toolInvocationId' => 'call_1', 'toolName' => 'weather', 'state' => 'result', 'input' => [], 'output' => 'sunny'],
                ['type' => 'text', 'text' => ' world'],
            ],
        ]);

        $textParts = $msg->getPartsByType('text');
        $this->assertCount(2, $textParts);
        $this->assertSame('Hello', $textParts[0]['text']);
        $this->assertSame(' world', $textParts[1]['text']);

        $toolParts = $msg->getPartsByType('tool-invocation');
        $this->assertCount(1, $toolParts);
        $this->assertSame('weather', $toolParts[0]['toolName']);
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
                    'type' => 'tool-invocation',
                    'toolInvocationId' => 'call_1',
                    'toolName' => 'getWeather',
                    'state' => 'result',
                    'input' => ['city' => 'SF'],
                    'output' => ['temp' => 72],
                ],
            ],
        ]);

        $tools = $msg->getToolInvocations();
        $this->assertCount(1, $tools);
        $this->assertSame('getWeather', $tools[0]['toolName']);
        $this->assertSame('result', $tools[0]['state']);
    }
}
