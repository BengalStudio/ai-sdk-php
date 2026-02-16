<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Types;

use BengalStudio\AI\Types\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function testSystemMessage(): void
    {
        $msg = Message::system('You are a helpful assistant.');

        $this->assertSame('system', $msg->role);
        $this->assertSame('You are a helpful assistant.', $msg->content);
        $this->assertNull($msg->providerOptions);
    }

    public function testUserMessageString(): void
    {
        $msg = Message::user('Hello');

        $this->assertSame('user', $msg->role);
        $this->assertSame('Hello', $msg->content);
    }

    public function testUserMessageMultipart(): void
    {
        $parts = [
            ['type' => 'text', 'text' => 'Describe this image:'],
            ['type' => 'image', 'url' => 'https://example.com/img.png'],
        ];
        $msg = Message::user($parts);

        $this->assertSame('user', $msg->role);
        $this->assertIsArray($msg->content);
        $this->assertCount(2, $msg->content);
    }

    public function testAssistantMessage(): void
    {
        $msg = Message::assistant('Sure, I can help!');

        $this->assertSame('assistant', $msg->role);
        $this->assertSame('Sure, I can help!', $msg->content);
    }

    public function testToolMessage(): void
    {
        $content = [
            ['type' => 'tool-result', 'toolCallId' => 'call_1', 'result' => '{"temp": 72}'],
        ];
        $msg = Message::tool($content);

        $this->assertSame('tool', $msg->role);
        $this->assertIsArray($msg->content);
    }

    public function testConstructorWithProviderOptions(): void
    {
        $msg = new Message(
            role: 'user',
            content: 'Hello',
            providerOptions: ['openai' => ['user' => 'user-123']],
        );

        $this->assertNotNull($msg->providerOptions);
        $this->assertSame('user-123', $msg->providerOptions['openai']['user']);
    }

    public function testToArray(): void
    {
        $msg = Message::user('Hello');
        $arr = $msg->toArray();

        $this->assertSame(['role' => 'user', 'content' => 'Hello'], $arr);
        $this->assertArrayNotHasKey('providerOptions', $arr);
    }

    public function testToArrayWithProviderOptions(): void
    {
        $msg = new Message(
            role: 'user',
            content: 'Hello',
            providerOptions: ['openai' => ['user' => 'u1']],
        );
        $arr = $msg->toArray();

        $this->assertArrayHasKey('providerOptions', $arr);
        $this->assertSame('u1', $arr['providerOptions']['openai']['user']);
    }
}
