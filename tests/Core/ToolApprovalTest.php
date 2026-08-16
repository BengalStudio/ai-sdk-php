<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Core;

use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Core\StreamText;
use BengalStudio\AI\Tool\Tool;
use BengalStudio\AI\Types\LanguageModelCallOptions;
use BengalStudio\AI\Types\LanguageModelGenerateResult;
use BengalStudio\AI\Types\LanguageModelStreamResult;
use BengalStudio\AI\Types\LanguageModelUsage;
use BengalStudio\AI\Types\Message;
use PHPUnit\Framework\TestCase;

/**
 * Pins the tool-approval loop: a gated call is announced and *not* run, the run
 * stops there, and a decision arriving on a later request settles it.
 *
 * The property worth guarding above all others is that a tool needing approval
 * does not execute. Several tests assert on a side-effect counter rather than on
 * the stream, because "was it announced" and "did it run" are different
 * questions and only the second one is a safety property.
 */
class ToolApprovalTest extends TestCase
{
    /**
     * A model that replays scripted parts and records the prompt it was given.
     *
     * @param array<int, array<string, mixed>> $parts
     */
    private function fakeModel(array $parts, ?array &$seenPrompt = null): LanguageModel
    {
        return new class($parts, $seenPrompt) implements LanguageModel {
            /** @param array<int, array<string, mixed>> $parts */
            public function __construct(
                private readonly array $parts,
                private mixed &$seenPrompt,
            ) {
            }

            public function specificationVersion(): string
            {
                return 'v3';
            }

            public function provider(): string
            {
                return 'fake';
            }

            public function modelId(): string
            {
                return 'fake-model';
            }

            public function doGenerate(LanguageModelCallOptions $options): LanguageModelGenerateResult
            {
                throw new \LogicException('doGenerate is not exercised here.');
            }

            public function doStream(LanguageModelCallOptions $options): LanguageModelStreamResult
            {
                $this->seenPrompt = $options->prompt;

                $parts = $this->parts;
                $gen = (function () use ($parts) {
                    foreach ($parts as $part) {
                        yield $part;
                    }
                })();

                return new LanguageModelStreamResult(stream: $gen);
            }
        };
    }

    /** A model that calls `deletePost` once and stops. */
    private function callsDeletePost(): array
    {
        return [
            [
                'type' => 'tool-call',
                'toolCallId' => 'call_1',
                'toolName' => 'deletePost',
                'input' => '{"id":5}',
            ],
            ['type' => 'finish', 'finishReason' => 'tool-calls', 'usage' => new LanguageModelUsage(10, 5)],
        ];
    }

    /**
     * @param null|bool|\Closure $needsApproval
     */
    private function deletePostTool(int &$ran, null|bool|\Closure $needsApproval, array &$sawOptions = []): Tool
    {
        return Tool::define(
            description: 'Delete a post',
            inputSchema: ['type' => 'object'],
            execute: function (array $input, array $options = []) use (&$ran, &$sawOptions) {
                $ran++;
                $sawOptions = $options;
                return ['deleted' => $input['id'] ?? null];
            },
            needsApproval: $needsApproval,
        );
    }

    // ─── Requesting ───

    public function testGatedCallIsAnnouncedAndNotExecuted(): void
    {
        $ran = 0;
        $result = (new StreamText($this->fakeModel($this->callsDeletePost())))
            ->prompt('delete post 5')
            ->tools(['deletePost' => $this->deletePostTool($ran, true)])
            ->maxSteps(5)
            ->execute();

        $parts = iterator_to_array($result->getFullStream(), false);
        $types = array_column($parts, 'type');

        $this->assertSame(0, $ran, 'a tool needing approval must not execute');
        $this->assertContains('tool-approval-request', $types);
        $this->assertNotContains('tool-result', $types);

        $request = $parts[array_search('tool-approval-request', $types, true)];
        $this->assertSame('call_1', $request['toolCallId']);
        $this->assertNotSame('', $request['approvalId']);
    }

    public function testUngatedCallStillExecutes(): void
    {
        $ran = 0;
        $result = (new StreamText($this->fakeModel($this->callsDeletePost())))
            ->prompt('delete post 5')
            ->tools(['deletePost' => $this->deletePostTool($ran, false)])
            ->execute();

        $types = array_column(iterator_to_array($result->getFullStream(), false), 'type');

        $this->assertSame(1, $ran);
        $this->assertContains('tool-result', $types);
        $this->assertNotContains('tool-approval-request', $types);
    }

    public function testClosureReceivesInputAndCanSupplyTheApprovalId(): void
    {
        $ran = 0;
        $seenInput = null;

        $tool = $this->deletePostTool($ran, function (array $input, array $options) use (&$seenInput) {
            $seenInput = $input;
            return 'approval-row-42';
        });

        $result = (new StreamText($this->fakeModel($this->callsDeletePost())))
            ->prompt('delete post 5')
            ->tools(['deletePost' => $tool])
            ->execute();

        $parts = iterator_to_array($result->getFullStream(), false);
        $request = $parts[array_search('tool-approval-request', array_column($parts, 'type'), true)];

        $this->assertSame(['id' => 5], $seenInput, 'the policy sees decoded input');
        $this->assertSame('approval-row-42', $request['approvalId'], 'a string return is used verbatim as the id');
        $this->assertSame(0, $ran);
    }

    /**
     * The step that mixes a gated call with an ungated one.
     *
     * This is the only shape that distinguishes "the run stopped because a human
     * has to decide" from "the run stopped because there was nothing to feed
     * back". With a gated call alone the loop would end either way, since it
     * produces no tool result; add an ungated call beside it and the loop has
     * something to continue on — and must not.
     */
    public function testAGatedCallStopsTheRunEvenWhenAnotherToolSucceeded(): void
    {
        $deleteRan = 0;
        $readRan = 0;
        $steps = 0;

        $script = [
            ['type' => 'tool-call', 'toolCallId' => 'call_1', 'toolName' => 'readPost', 'input' => '{"id":5}'],
            ['type' => 'tool-call', 'toolCallId' => 'call_2', 'toolName' => 'deletePost', 'input' => '{"id":5}'],
            ['type' => 'finish', 'finishReason' => 'tool-calls', 'usage' => new LanguageModelUsage(10, 5)],
        ];

        $readPost = Tool::define(
            description: 'Read a post',
            inputSchema: ['type' => 'object'],
            execute: function (array $input) use (&$readRan) {
                $readRan++;
                return ['title' => 'Hello'];
            },
        );

        $result = (new StreamText($this->fakeModel($script)))
            ->prompt('delete post 5')
            ->tools([
                'readPost' => $readPost,
                'deletePost' => $this->deletePostTool($deleteRan, true),
            ])
            ->maxSteps(5)
            ->onStepFinish(function () use (&$steps): void {
                $steps++;
            })
            ->execute();

        $types = array_column(iterator_to_array($result->getFullStream(), false), 'type');

        $this->assertSame(1, $readRan, 'the ungated call still runs');
        $this->assertSame(0, $deleteRan, 'the gated call does not');
        $this->assertContains('tool-result', $types);
        $this->assertContains('tool-approval-request', $types);
        $this->assertSame(1, $steps, 'the run ends at the approval rather than narrating a decision nobody made');
    }

    // ─── Resuming ───

    /**
     * The shape `convertToModelMessages()` produces for a decided approval.
     */
    private function approvalResponse(bool $approved, ?string $reason = null): array
    {
        return array_filter([
            'type' => 'tool-approval-response',
            'toolCallId' => 'call_1',
            'toolName' => 'deletePost',
            'input' => ['id' => 5],
            'approvalId' => 'approval-row-42',
            'approved' => $approved,
            'reason' => $reason,
        ], fn($v) => $v !== null);
    }

    /** History as it arrives on the request after a decision. */
    private function decidedHistory(bool $approved, ?string $reason = null): array
    {
        return [
            Message::user('delete post 5'),
            Message::assistant([
                ['type' => 'tool-call', 'toolCallId' => 'call_1', 'toolName' => 'deletePost', 'input' => ['id' => 5]],
            ]),
            Message::tool([$this->approvalResponse($approved, $reason)]),
        ];
    }

    public function testApprovedCallExecutesOnResumeAndCarriesTheApproval(): void
    {
        $ran = 0;
        $sawOptions = [];
        $seenPrompt = null;

        $result = (new StreamText($this->fakeModel(
            [['type' => 'text-delta', 'textDelta' => 'Done.'],
             ['type' => 'finish', 'finishReason' => 'stop', 'usage' => new LanguageModelUsage(1, 1)]],
            $seenPrompt
        )))
            ->messages($this->decidedHistory(true))
            ->tools(['deletePost' => $this->deletePostTool($ran, true, $sawOptions)])
            ->execute();

        $parts = iterator_to_array($result->getFullStream(), false);
        $types = array_column($parts, 'type');

        $this->assertSame(1, $ran, 'an approved call runs on resume');

        // The approval reaches the tool, so a consumer can redeem what it recorded.
        $this->assertSame(
            ['id' => 'approval-row-42', 'approved' => true],
            $sawOptions['approval'] ?? null
        );

        // The result is announced so the client can close the card it is showing.
        $this->assertContains('tool-result', $types);
        $resultChunk = $parts[array_search('tool-result', $types, true)];
        $this->assertSame('call_1', $resultChunk['toolCallId']);
        $this->assertSame(['deleted' => 5], $resultChunk['result']);

        // `needsApproval` is not consulted again — the call was already decided.
        $this->assertNotContains('tool-approval-request', $types);
    }

    public function testResumedPromptCarriesARealToolResultNotTheProtocol(): void
    {
        $ran = 0;
        $seenPrompt = null;

        $result = (new StreamText($this->fakeModel(
            [['type' => 'finish', 'finishReason' => 'stop', 'usage' => new LanguageModelUsage(1, 1)]],
            $seenPrompt
        )))
            ->messages($this->decidedHistory(true))
            ->tools(['deletePost' => $this->deletePostTool($ran, true)])
            ->execute();

        iterator_to_array($result->getFullStream(), false);

        $toolMessages = array_values(array_filter(
            $seenPrompt,
            fn(Message $m) => $m->role === 'tool'
        ));
        $this->assertCount(1, $toolMessages);

        $partTypes = array_column($toolMessages[0]->content, 'type');
        $this->assertSame(['tool-result'], $partTypes, 'no provider should ever see tool-approval-response');
        $this->assertSame('call_1', $toolMessages[0]->content[0]['toolCallId']);
    }

    public function testDeniedCallDoesNotExecuteAndTellsTheModelWhy(): void
    {
        $ran = 0;
        $seenPrompt = null;

        $result = (new StreamText($this->fakeModel(
            [['type' => 'finish', 'finishReason' => 'stop', 'usage' => new LanguageModelUsage(1, 1)]],
            $seenPrompt
        )))
            ->messages($this->decidedHistory(false, 'too risky'))
            ->tools(['deletePost' => $this->deletePostTool($ran, true)])
            ->execute();

        $parts = iterator_to_array($result->getFullStream(), false);
        $types = array_column($parts, 'type');

        $this->assertSame(0, $ran, 'a denied call must never execute');
        $this->assertContains('tool-output-denied', $types);

        $denied = $parts[array_search('tool-output-denied', $types, true)];
        $this->assertSame('call_1', $denied['toolCallId']);
        $this->assertSame('too risky', $denied['reason']);

        // The call is still resolved for the model: an assistant tool-call with
        // no tool result is a hard 400 from OpenAI.
        $toolMessages = array_values(array_filter($seenPrompt, fn(Message $m) => $m->role === 'tool'));
        $this->assertCount(1, $toolMessages);
        $this->assertSame('tool-result', $toolMessages[0]->content[0]['type']);
        $this->assertStringContainsString('denied', $toolMessages[0]->content[0]['result']);
        $this->assertStringContainsString('too risky', $toolMessages[0]->content[0]['result']);
    }

    public function testApprovedCallForAMissingToolReportsRatherThanPretends(): void
    {
        $seenPrompt = null;

        $result = (new StreamText($this->fakeModel(
            [['type' => 'finish', 'finishReason' => 'stop', 'usage' => new LanguageModelUsage(1, 1)]],
            $seenPrompt
        )))
            ->messages($this->decidedHistory(true))
            ->tools([]) // the tool is gone — renamed, or not resolved this request
            ->execute();

        $parts = iterator_to_array($result->getFullStream(), false);
        $types = array_column($parts, 'type');

        $this->assertContains('tool-output-error', $types);

        // Still resolved, so the turn is replayable.
        $toolMessages = array_values(array_filter($seenPrompt, fn(Message $m) => $m->role === 'tool'));
        $this->assertCount(1, $toolMessages);
        $this->assertSame('tool-result', $toolMessages[0]->content[0]['type']);
    }

    public function testOrdinaryToolResultsPassThroughUntouched(): void
    {
        $ran = 0;
        $seenPrompt = null;

        $history = [
            Message::user('hi'),
            Message::assistant([
                ['type' => 'tool-call', 'toolCallId' => 'call_9', 'toolName' => 'deletePost', 'input' => ['id' => 1]],
            ]),
            Message::tool([
                ['type' => 'tool-result', 'toolCallId' => 'call_9', 'result' => '{"deleted":1}'],
            ]),
        ];

        $result = (new StreamText($this->fakeModel(
            [['type' => 'finish', 'finishReason' => 'stop', 'usage' => new LanguageModelUsage(1, 1)]],
            $seenPrompt
        )))
            ->messages($history)
            ->tools(['deletePost' => $this->deletePostTool($ran, true)])
            ->execute();

        iterator_to_array($result->getFullStream(), false);

        $this->assertSame(0, $ran, 'a settled turn is not re-executed');
        $toolMessages = array_values(array_filter($seenPrompt, fn(Message $m) => $m->role === 'tool'));
        $this->assertSame(
            [['type' => 'tool-result', 'toolCallId' => 'call_9', 'result' => '{"deleted":1}']],
            $toolMessages[0]->content
        );
    }
}
