<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Core;

use BengalStudio\AI\Types\Message;
use PHPUnit\Framework\TestCase;

use function BengalStudio\AI\convertToModelMessages;

class ConvertToModelMessagesTest extends TestCase
{
    public function testConvertsSimpleTextMessages(): void
    {
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'user',
                'parts' => [
                    ['type' => 'text', 'text' => 'Hello!'],
                ],
            ],
            [
                'id' => 'msg_2',
                'role' => 'assistant',
                'parts' => [
                    ['type' => 'text', 'text' => 'Hi there!'],
                ],
            ],
            [
                'id' => 'msg_3',
                'role' => 'user',
                'parts' => [
                    ['type' => 'text', 'text' => 'How are you?'],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        $this->assertCount(3, $messages);

        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('Hello!', $messages[0]->content);

        $this->assertSame('assistant', $messages[1]->role);
        $this->assertSame('Hi there!', $messages[1]->content);

        $this->assertSame('user', $messages[2]->role);
        $this->assertSame('How are you?', $messages[2]->content);
    }

    public function testConvertsSystemMessage(): void
    {
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'system',
                'parts' => [
                    ['type' => 'text', 'text' => 'You are a helpful assistant.'],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        $this->assertCount(1, $messages);
        $this->assertSame('system', $messages[0]->role);
        $this->assertSame('You are a helpful assistant.', $messages[0]->content);
    }

    public function testConvertsAssistantWithToolInvocation(): void
    {
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'user',
                'parts' => [
                    ['type' => 'text', 'text' => 'What is the weather in SF?'],
                ],
            ],
            [
                'id' => 'msg_2',
                'role' => 'assistant',
                'parts' => [
                    ['type' => 'text', 'text' => 'Let me check the weather.'],
                    [
                        'type' => 'tool-invocation',
                        'toolInvocationId' => 'call_abc123',
                        'toolName' => 'getWeather',
                        'state' => 'result',
                        'input' => ['city' => 'San Francisco'],
                        'output' => ['temp' => 72, 'condition' => 'sunny'],
                    ],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        // Should produce: user, assistant (with text + tool-call), tool (with tool-result)
        $this->assertCount(3, $messages);

        // User message
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('What is the weather in SF?', $messages[0]->content);

        // Assistant message with mixed content
        $this->assertSame('assistant', $messages[1]->role);
        $this->assertIsArray($messages[1]->content);
        $this->assertCount(2, $messages[1]->content);
        $this->assertSame('text', $messages[1]->content[0]['type']);
        $this->assertSame('Let me check the weather.', $messages[1]->content[0]['text']);
        $this->assertSame('tool-call', $messages[1]->content[1]['type']);
        $this->assertSame('call_abc123', $messages[1]->content[1]['toolCallId']);
        $this->assertSame('getWeather', $messages[1]->content[1]['toolName']);

        // Tool result message
        $this->assertSame('tool', $messages[2]->role);
        $this->assertIsArray($messages[2]->content);
        $this->assertCount(1, $messages[2]->content);
        $this->assertSame('tool-result', $messages[2]->content[0]['type']);
        $this->assertSame('call_abc123', $messages[2]->content[0]['toolCallId']);
    }

    public function testConvertsAssistantTextOnlyAsString(): void
    {
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    ['type' => 'text', 'text' => 'Hello there!'],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        $this->assertCount(1, $messages);
        $this->assertSame('assistant', $messages[0]->role);
        // Pure text assistant content should be a string, not array
        $this->assertSame('Hello there!', $messages[0]->content);
    }

    public function testConvertsMultipleTextParts(): void
    {
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'user',
                'parts' => [
                    ['type' => 'text', 'text' => 'First part. '],
                    ['type' => 'text', 'text' => 'Second part.'],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        $this->assertCount(1, $messages);
        $this->assertSame('First part. Second part.', $messages[0]->content);
    }

    public function testConvertsUserWithImageFile(): void
    {
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'user',
                'parts' => [
                    ['type' => 'text', 'text' => 'What is in this image?'],
                    ['type' => 'file', 'url' => 'data:image/png;base64,abc123', 'mediaType' => 'image/png'],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]->role);
        $this->assertIsArray($messages[0]->content);
        $this->assertCount(2, $messages[0]->content);
        $this->assertSame('text', $messages[0]->content[0]['type']);
        $this->assertSame('image', $messages[0]->content[1]['type']);
        $this->assertSame('data:image/png;base64,abc123', $messages[0]->content[1]['image']);
        $this->assertSame('image/png', $messages[0]->content[1]['mimeType']);
    }

    public function testConvertsToolInvocationWithoutResult(): void
    {
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    [
                        'type' => 'tool-invocation',
                        'toolInvocationId' => 'call_1',
                        'toolName' => 'search',
                        'state' => 'call',
                        'input' => ['query' => 'php streaming'],
                    ],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        // Only assistant message, no tool result
        $this->assertCount(1, $messages);
        $this->assertSame('assistant', $messages[0]->role);
        $this->assertIsArray($messages[0]->content);
        $this->assertSame('tool-call', $messages[0]->content[0]['type']);
    }

    public function testSkipsEmptyMessages(): void
    {
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'user',
                'parts' => [],
            ],
            [
                'id' => 'msg_2',
                'role' => 'user',
                'parts' => [
                    ['type' => 'text', 'text' => 'Hello'],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        $this->assertCount(1, $messages);
        $this->assertSame('Hello', $messages[0]->content);
    }

    public function testConvertsFullConversation(): void
    {
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'user',
                'parts' => [['type' => 'text', 'text' => 'What is the weather?']],
            ],
            [
                'id' => 'msg_2',
                'role' => 'assistant',
                'parts' => [
                    ['type' => 'text', 'text' => 'Checking...'],
                    [
                        'type' => 'tool-invocation',
                        'toolInvocationId' => 'call_1',
                        'toolName' => 'weather',
                        'state' => 'result',
                        'input' => ['city' => 'NYC'],
                        'output' => '72F sunny',
                    ],
                    ['type' => 'text', 'text' => ' The weather is 72F and sunny!'],
                ],
            ],
            [
                'id' => 'msg_3',
                'role' => 'user',
                'parts' => [['type' => 'text', 'text' => 'Thanks!']],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        // user, assistant (mixed), tool, user
        $this->assertCount(4, $messages);
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('assistant', $messages[1]->role);
        $this->assertSame('tool', $messages[2]->role);
        $this->assertSame('user', $messages[3]->role);
    }
}
