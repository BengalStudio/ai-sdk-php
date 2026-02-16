<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Prompt;

use BengalStudio\AI\Prompt\PromptConverter;
use BengalStudio\AI\Types\Message;
use PHPUnit\Framework\TestCase;

class PromptConverterTest extends TestCase
{
    public function testStringPrompt(): void
    {
        $result = PromptConverter::standardize(prompt: 'Hello');

        $this->assertCount(1, $result);
        $this->assertSame('user', $result[0]->role);
        $this->assertSame('Hello', $result[0]->content);
    }

    public function testStringPromptWithSystem(): void
    {
        $result = PromptConverter::standardize(
            prompt: 'Hello',
            system: 'You are helpful.',
        );

        $this->assertCount(2, $result);
        $this->assertSame('system', $result[0]->role);
        $this->assertSame('You are helpful.', $result[0]->content);
        $this->assertSame('user', $result[1]->role);
    }

    public function testMessages(): void
    {
        $messages = [
            Message::system('Be helpful'),
            Message::user('Hi'),
            Message::assistant('Hello!'),
        ];

        $result = PromptConverter::standardize(messages: $messages);

        $this->assertCount(3, $result);
        $this->assertSame('system', $result[0]->role);
        $this->assertSame('user', $result[1]->role);
        $this->assertSame('assistant', $result[2]->role);
    }

    public function testBothPromptAndMessagesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not both');

        PromptConverter::standardize(
            prompt: 'Hello',
            messages: [Message::user('Hi')],
        );
    }

    public function testNeitherPromptNorMessagesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must provide');

        PromptConverter::standardize();
    }

    public function testToLanguageModelPrompt(): void
    {
        $messages = [Message::user('Hello'), Message::assistant('Hi')];
        $result = PromptConverter::toLanguageModelPrompt($messages);

        $this->assertCount(2, $result);
        $this->assertSame('user', $result[0]['role']);
        $this->assertSame('Hello', $result[0]['content']);
    }
}
