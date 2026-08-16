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

    public function testConvertsAssistantWithToolCall(): void
    {
        // AI SDK v5+ static tool part: type `tool-<name>`, state
        // `output-available`, name encoded in the type suffix (no toolName field).
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
                        'type' => 'tool-getWeather',
                        'toolCallId' => 'call_abc123',
                        'state' => 'output-available',
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
        $this->assertSame(['city' => 'San Francisco'], $messages[1]->content[1]['input']);

        // Tool result message
        $this->assertSame('tool', $messages[2]->role);
        $this->assertIsArray($messages[2]->content);
        $this->assertCount(1, $messages[2]->content);
        $this->assertSame('tool-result', $messages[2]->content[0]['type']);
        $this->assertSame('call_abc123', $messages[2]->content[0]['toolCallId']);
        $this->assertSame('getWeather', $messages[2]->content[0]['toolName']);
        // Non-string output is JSON-encoded into `result` (the key the OpenAI
        // converter reads via `output ?? result`).
        $this->assertSame('{"temp":72,"condition":"sunny"}', $messages[2]->content[0]['result']);
    }

    public function testConvertsToolOutputError(): void
    {
        // output-error: input may be absent — fall back to rawInput — and the
        // errorText becomes the tool result so OpenAI still gets a matching
        // tool message for the tool_call_id.
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    [
                        'type' => 'tool-getWeather',
                        'toolCallId' => 'call_err',
                        'state' => 'output-error',
                        'errorText' => 'Service unavailable',
                        'rawInput' => ['city' => 'SF'],
                    ],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        $this->assertCount(2, $messages);
        $this->assertSame('assistant', $messages[0]->role);
        $this->assertSame('tool-call', $messages[0]->content[0]['type']);
        $this->assertSame('call_err', $messages[0]->content[0]['toolCallId']);
        $this->assertSame(['city' => 'SF'], $messages[0]->content[0]['input']);

        $this->assertSame('tool', $messages[1]->role);
        $this->assertSame('tool-result', $messages[1]->content[0]['type']);
        $this->assertSame('call_err', $messages[1]->content[0]['toolCallId']);
        $this->assertSame('Service unavailable', $messages[1]->content[0]['result']);
    }

    public function testConvertsDynamicToolPart(): void
    {
        // dynamic-tool parts carry the tool name in an explicit field.
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    [
                        'type' => 'dynamic-tool',
                        'toolName' => 'search',
                        'toolCallId' => 'call_dyn',
                        'state' => 'output-available',
                        'input' => ['q' => 'php'],
                        'output' => 'results',
                    ],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        $this->assertCount(2, $messages);
        $this->assertSame('tool-call', $messages[0]->content[0]['type']);
        $this->assertSame('search', $messages[0]->content[0]['toolName']);
        $this->assertSame('tool', $messages[1]->role);
        $this->assertSame('search', $messages[1]->content[0]['toolName']);
        $this->assertSame('results', $messages[1]->content[0]['result']);
    }

    /**
     * Each `step-start` opens a new call/result pair, so a turn that called
     * two tools and then answered replays as five messages in causal order —
     * not one assistant message holding all of it.
     */
    public function testConvertsMultiStepToolTurn(): void
    {
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    ['type' => 'step-start'],
                    ['type' => 'tool-alpha', 'toolCallId' => 'c1', 'state' => 'output-available', 'input' => [], 'output' => 'r1'],
                    ['type' => 'step-start'],
                    ['type' => 'tool-beta', 'toolCallId' => 'c2', 'state' => 'output-available', 'input' => [], 'output' => 'r2'],
                    ['type' => 'step-start'],
                    ['type' => 'text', 'text' => 'Done'],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        $this->assertSame(
            ['assistant', 'tool', 'assistant', 'tool', 'assistant'],
            array_map(fn($m) => $m->role, $messages),
        );

        $this->assertSame('c1', $messages[0]->content[0]['toolCallId']);
        $this->assertSame('c1', $messages[1]->content[0]['toolCallId']);
        $this->assertSame('c2', $messages[2]->content[0]['toolCallId']);
        $this->assertSame('c2', $messages[3]->content[0]['toolCallId']);

        // The answer lands after the result that produced it.
        $this->assertSame('Done', $messages[4]->content);
    }

    /**
     * The shape a search-then-answer turn actually persists as. Flattening it
     * put the answer ahead of the tool result, which a provider that pairs a
     * call to its result positionally reads as the model answering early.
     */
    public function testToolResultPrecedesTheAnswerItProduced(): void
    {
        $messages = convertToModelMessages([
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    ['type' => 'step-start'],
                    ['type' => 'tool-search', 'toolCallId' => 'c1', 'state' => 'output-available', 'input' => [], 'output' => 'r1'],
                    ['type' => 'step-start'],
                    ['type' => 'text', 'text' => 'Here are some products.'],
                ],
            ],
        ]);

        $this->assertSame(['assistant', 'tool', 'assistant'], array_map(fn($m) => $m->role, $messages));
        $this->assertSame('tool-call', $messages[0]->content[0]['type']);
        $this->assertSame('Here are some products.', $messages[2]->content);
    }

    /**
     * Narration before a call belongs to that call's step, ahead of it.
     */
    public function testTextBeforeAToolCallStaysInThatStep(): void
    {
        $messages = convertToModelMessages([
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    ['type' => 'step-start'],
                    ['type' => 'text', 'text' => 'Let me look.'],
                    ['type' => 'tool-search', 'toolCallId' => 'c1', 'state' => 'output-available', 'input' => [], 'output' => 'r1'],
                    ['type' => 'step-start'],
                    ['type' => 'text', 'text' => 'Found it.'],
                ],
            ],
        ]);

        $this->assertSame(['assistant', 'tool', 'assistant'], array_map(fn($m) => $m->role, $messages));
        $this->assertSame('text', $messages[0]->content[0]['type']);
        $this->assertSame('Let me look.', $messages[0]->content[0]['text']);
        $this->assertSame('tool-call', $messages[0]->content[1]['type']);
    }

    /**
     * Parallel calls resolve inside one step, so they stay in one pair.
     */
    public function testParallelCallsInOneStepShareOneToolMessage(): void
    {
        $messages = convertToModelMessages([
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    ['type' => 'step-start'],
                    ['type' => 'tool-a', 'toolCallId' => 'c1', 'state' => 'output-available', 'input' => [], 'output' => 'r1'],
                    ['type' => 'tool-b', 'toolCallId' => 'c2', 'state' => 'output-available', 'input' => [], 'output' => 'r2'],
                    ['type' => 'step-start'],
                    ['type' => 'text', 'text' => 'Both done.'],
                ],
            ],
        ]);

        $this->assertSame(['assistant', 'tool', 'assistant'], array_map(fn($m) => $m->role, $messages));
        $this->assertCount(2, $messages[0]->content);
        $this->assertCount(2, $messages[1]->content);
        $this->assertSame('c1', $messages[1]->content[0]['toolCallId']);
        $this->assertSame('c2', $messages[1]->content[1]['toolCallId']);
    }

    /**
     * History written before step markers were persisted forms one step, so
     * it converts exactly as it did before.
     */
    public function testPartsWithoutStepMarkersConvertAsASingleStep(): void
    {
        $messages = convertToModelMessages([
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    ['type' => 'tool-search', 'toolCallId' => 'c1', 'state' => 'output-available', 'input' => [], 'output' => 'r1'],
                    ['type' => 'text', 'text' => 'Here you go.'],
                ],
            ],
        ]);

        $this->assertSame(['assistant', 'tool'], array_map(fn($m) => $m->role, $messages));
        $this->assertCount(2, $messages[0]->content);
    }

    /**
     * A step holding nothing replayable contributes no message — an empty
     * assistant message is rejected by several providers.
     */
    public function testStepsWithNothingReplayableAreDropped(): void
    {
        $messages = convertToModelMessages([
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    ['type' => 'step-start'],
                    ['type' => 'tool-pending', 'toolCallId' => 'c1', 'state' => 'input-available', 'input' => []],
                    ['type' => 'step-start'],
                    ['type' => 'text', 'text' => 'Answer.'],
                ],
            ],
        ]);

        $this->assertCount(1, $messages);
        $this->assertSame('Answer.', $messages[0]->content);
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

    public function testSkipsIncompleteToolCall(): void
    {
        // An unresolved tool call (state input-available/input-streaming) is
        // skipped entirely: emitting an assistant tool-call with no matching
        // tool message is a hard OpenAI 400. Persisted history never contains
        // these (it is saved after the stream finishes), so this is defensive.
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    [
                        'type' => 'tool-search',
                        'toolCallId' => 'call_1',
                        'state' => 'input-available',
                        'input' => ['query' => 'php streaming'],
                    ],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        // Nothing emitted: no orphaned tool-call, no tool message.
        $this->assertCount(0, $messages);
    }

    public function testSkipsLegacyV4ToolInvocation(): void
    {
        // Legacy v4 parts (type 'tool-invocation', state 'result'/'call') are
        // no longer produced by any client. They fall through the v6 state
        // guard and are skipped rather than mishandled.
        $uiMessages = [
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    [
                        'type' => 'tool-invocation',
                        'toolInvocationId' => 'call_1',
                        'toolName' => 'search',
                        'state' => 'result',
                        'input' => ['query' => 'x'],
                        'output' => 'y',
                    ],
                ],
            ],
        ];

        $messages = convertToModelMessages($uiMessages);

        $this->assertCount(0, $messages);
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
                        'type' => 'tool-weather',
                        'toolCallId' => 'call_1',
                        'state' => 'output-available',
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

    // ─── Tool approvals ───

    public function testDecidedApprovalReplaysAsCallPlusApprovalResponse(): void
    {
        $messages = convertToModelMessages([
            [
                'id' => 'msg_1',
                'role' => 'user',
                'parts' => [['type' => 'text', 'text' => 'delete post 5']],
            ],
            [
                'id' => 'msg_2',
                'role' => 'assistant',
                'parts' => [
                    [
                        'type' => 'tool-deletePost',
                        'toolCallId' => 'call_1',
                        'state' => 'approval-responded',
                        'input' => ['id' => 5],
                        'approval' => ['id' => 'approval_1', 'approved' => true, 'reason' => 'ok'],
                    ],
                ],
            ],
        ]);

        $this->assertCount(3, $messages);
        $this->assertSame('assistant', $messages[1]->role);
        $this->assertSame('tool-call', $messages[1]->content[0]['type']);
        $this->assertSame('call_1', $messages[1]->content[0]['toolCallId']);

        // The decision, not a result — the call has not run yet. It carries
        // everything StreamText needs to run it, because by the time a decision
        // arrives the turn that produced the call is over.
        $this->assertSame('tool', $messages[2]->role);
        $response = $messages[2]->content[0];
        $this->assertSame('tool-approval-response', $response['type']);
        $this->assertSame('approval_1', $response['approvalId']);
        $this->assertTrue($response['approved']);
        $this->assertSame('ok', $response['reason']);
        $this->assertSame('call_1', $response['toolCallId']);
        $this->assertSame('deletePost', $response['toolName']);
        $this->assertSame(['id' => 5], $response['input']);
    }

    public function testDeniedApprovalReplaysWithApprovedFalse(): void
    {
        $messages = convertToModelMessages([
            [
                'id' => 'msg_1',
                'role' => 'assistant',
                'parts' => [
                    [
                        'type' => 'tool-deletePost',
                        'toolCallId' => 'call_1',
                        'state' => 'approval-responded',
                        'input' => ['id' => 5],
                        'approval' => ['id' => 'approval_1', 'approved' => false],
                    ],
                ],
            ],
        ]);

        $response = $messages[1]->content[0];
        $this->assertFalse($response['approved']);
        $this->assertArrayNotHasKey('reason', $response, 'an absent reason is omitted, not sent as null');
    }

    public function testUndecidedApprovalIsSkippedEntirely(): void
    {
        $messages = convertToModelMessages([
            [
                'id' => 'msg_1',
                'role' => 'user',
                'parts' => [['type' => 'text', 'text' => 'delete post 5']],
            ],
            [
                'id' => 'msg_2',
                'role' => 'assistant',
                'parts' => [
                    ['type' => 'text', 'text' => 'One moment.'],
                    [
                        'type' => 'tool-deletePost',
                        'toolCallId' => 'call_1',
                        'state' => 'approval-requested',
                        'input' => ['id' => 5],
                        'approval' => ['id' => 'approval_1'],
                    ],
                ],
            ],
        ]);

        // Nobody has decided, so the call has no result and never will unless a
        // human acts. Replaying it would leave an assistant tool-call with no
        // matching tool message — a hard 400 from OpenAI.
        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('assistant', $messages[1]->role);
        $this->assertSame('One moment.', $messages[1]->content);

        foreach ($messages as $message) {
            $this->assertNotSame('tool', $message->role);
        }
    }
}
