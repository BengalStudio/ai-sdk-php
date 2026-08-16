<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Prompt\CallSettings;
use BengalStudio\AI\Prompt\PromptConverter;
use BengalStudio\AI\Tool\Tool;
use BengalStudio\AI\Tool\ToolCall;
use BengalStudio\AI\Tool\ToolPreparer;
use BengalStudio\AI\Tool\ToolResult;
use BengalStudio\AI\Types\LanguageModelCallOptions;
use BengalStudio\AI\Types\LanguageModelUsage;
use BengalStudio\AI\Types\Message;
use BengalStudio\AI\Util\Retry;

/**
 * Stream text and call tools for a given prompt using a language model.
 *
 * This function streams the output. If you do not want to stream
 * the output, use generateText() instead.
 *
 * Mirrors Vercel AI SDK's streamText function.
 */
class StreamText
{
    private LanguageModel $model;
    private ?string $prompt = null;
    private ?string $system = null;
    /** @var Message[]|null */
    private ?array $messages = null;
    /** @var array<string, Tool>|null */
    private ?array $tools = null;
    private string|array|null $toolChoice = null;
    private int $maxRetries = 2;
    private int $maxSteps = 1;
    private array $settings = [];
    private ?array $providerOptions = null;
    private ?\Closure $onFinish = null;
    private ?\Closure $onChunk = null;
    private ?\Closure $onStepFinish = null;
    /** @var array<int, callable> */
    private array $transforms = [];

    public function __construct(LanguageModel $model)
    {
        $this->model = $model;
    }

    public function prompt(string $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    public function system(string $system): self
    {
        $this->system = $system;
        return $this;
    }

    /**
     * @param Message[] $messages
     */
    public function messages(array $messages): self
    {
        $this->messages = $messages;
        return $this;
    }

    /**
     * @param array<string, Tool> $tools
     */
    public function tools(array $tools): self
    {
        $this->tools = $tools;
        return $this;
    }

    public function toolChoice(string|array $toolChoice): self
    {
        $this->toolChoice = $toolChoice;
        return $this;
    }

    public function maxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    public function maxSteps(int $maxSteps): self
    {
        $this->maxSteps = $maxSteps;
        return $this;
    }

    public function maxOutputTokens(int $tokens): self
    {
        $this->settings['maxOutputTokens'] = $tokens;
        return $this;
    }

    public function temperature(float $temperature): self
    {
        $this->settings['temperature'] = $temperature;
        return $this;
    }

    public function topP(float $topP): self
    {
        $this->settings['topP'] = $topP;
        return $this;
    }

    public function topK(int $topK): self
    {
        $this->settings['topK'] = $topK;
        return $this;
    }

    public function frequencyPenalty(float $penalty): self
    {
        $this->settings['frequencyPenalty'] = $penalty;
        return $this;
    }

    public function presencePenalty(float $penalty): self
    {
        $this->settings['presencePenalty'] = $penalty;
        return $this;
    }

    public function stopSequences(array $sequences): self
    {
        $this->settings['stopSequences'] = $sequences;
        return $this;
    }

    public function seed(int $seed): self
    {
        $this->settings['seed'] = $seed;
        return $this;
    }

    public function providerOptions(array $options): self
    {
        $this->providerOptions = $options;
        return $this;
    }

    public function onFinish(\Closure $callback): self
    {
        $this->onFinish = $callback;
        return $this;
    }

    public function onChunk(\Closure $callback): self
    {
        $this->onChunk = $callback;
        return $this;
    }

    public function onStepFinish(\Closure $callback): self
    {
        $this->onStepFinish = $callback;
        return $this;
    }

    /**
     * Register one or more stream transforms applied in order to the full
     * stream produced by execute(). Each transform is a generator-to-generator
     * wrapper: callable(\Generator): \Generator.
     *
     * @param callable|array<int, callable> $transform
     */
    public function transform(callable|array $transform): self
    {
        $transforms = is_array($transform) ? $transform : [$transform];
        foreach ($transforms as $t) {
            if (!is_callable($t)) {
                throw new \InvalidArgumentException(
                    'Each transform must be callable(\\Generator): \\Generator.'
                );
            }
        }
        $this->transforms = array_merge($this->transforms, $transforms);
        return $this;
    }

    /**
     * Execute the streaming text generation.
     */
    public function execute(): StreamTextResult
    {
        $promptMessages = PromptConverter::standardize(
            prompt: $this->prompt,
            system: $this->system,
            messages: $this->messages,
        );

        $validatedSettings = CallSettings::prepare($this->settings);
        $prepared = ToolPreparer::prepare($this->tools, $this->toolChoice);
        $transforms = $this->transforms;

        return new StreamTextResult(
            streamFactory: function () use (
                $promptMessages,
                $validatedSettings,
                $prepared,
                $transforms,
            ) {
                $stream = $this->createStream(
                    $promptMessages,
                    $validatedSettings,
                    $prepared
                );
                foreach ($transforms as $t) {
                    $stream = $t($stream);
                }
                return $stream;
            },
            onFinish: $this->onFinish,
        );
    }

    /**
     * Create the multi-step stream generator.
     *
     * @return \Generator
     */
    private function createStream(
        array $promptMessages,
        array $validatedSettings,
        array $prepared,
    ): \Generator {
        // A run that was held for approval resumes here: decisions the client
        // sent back are settled — executed or denied — before the model is
        // asked anything, so it opens the turn with the results in hand.
        $settle = $this->settleApprovals($promptMessages);
        foreach ($settle as $settledChunk) {
            yield $settledChunk;
        }
        $currentMessages = $settle->getReturn();

        $totalUsage = new LanguageModelUsage();

        for ($step = 0; $step < $this->maxSteps; $step++) {
            $options = new LanguageModelCallOptions(
                prompt: $currentMessages,
                maxOutputTokens: $validatedSettings['maxOutputTokens'] ?? null,
                temperature: $validatedSettings['temperature'] ?? null,
                topP: $validatedSettings['topP'] ?? null,
                topK: $validatedSettings['topK'] ?? null,
                frequencyPenalty: $validatedSettings['frequencyPenalty'] ?? null,
                presencePenalty: $validatedSettings['presencePenalty'] ?? null,
                stopSequences: $validatedSettings['stopSequences'] ?? null,
                seed: $validatedSettings['seed'] ?? null,
                tools: $prepared['tools'],
                toolChoice: $prepared['toolChoice'],
                providerOptions: $this->providerOptions,
                responseFormat: $this->settings['responseFormat'] ?? null,
            );

            $streamResult = Retry::execute(
                fn() => $this->model->doStream($options),
                maxRetries: $this->maxRetries,
            );

            $textAccumulator = '';
            $toolCalls = [];
            $finishReason = null;
            $usage = null;

            foreach ($streamResult->getStream() as $chunk) {
                $type = $chunk['type'] ?? 'unknown';

                switch ($type) {
                    case 'text-delta':
                        $delta = $chunk['textDelta'] ?? '';
                        $textAccumulator .= $delta;
                        yield [
                            'type' => 'text-delta',
                            'textDelta' => $delta,
                            'step' => $step,
                        ];
                        if ($this->onChunk !== null) {
                            ($this->onChunk)($chunk);
                        }
                        break;

                    case 'tool-call':
                        $toolCalls[] = $chunk;
                        // Decode input: models yield 'input' as a JSON string,
                        // but consumers expect a decoded array under 'args'.
                        $rawInput = $chunk['input'] ?? $chunk['args'] ?? [];
                        $decodedInput = is_string($rawInput) ? json_decode($rawInput, true) : $rawInput;
                        yield [
                            'type' => 'tool-call',
                            'toolCallId' => $chunk['toolCallId'] ?? '',
                            'toolName' => $chunk['toolName'] ?? '',
                            'args' => $decodedInput,
                            'step' => $step,
                        ];
                        if ($this->onChunk !== null) {
                            ($this->onChunk)($chunk);
                        }
                        break;

                    case 'tool-input-start':
                        // Tool-call argument streaming has begun (model-level).
                        // Forward unchanged — keep the language-model `id`/`delta`
                        // keys; the UI-stream serializer renames them to
                        // `toolCallId`/`inputTextDelta`. (Vercel keeps LM-level
                        // keys through the core too.)
                        yield array_merge($chunk, ['step' => $step]);
                        if ($this->onChunk !== null) {
                            ($this->onChunk)($chunk);
                        }
                        break;

                    case 'tool-input-delta':
                    // `tool-call-delta` is a back-compat alias: no current
                    // provider emits it; both providers emit `tool-input-delta`.
                    case 'tool-call-delta':
                        yield array_merge($chunk, ['step' => $step]);
                        if ($this->onChunk !== null) {
                            ($this->onChunk)($chunk);
                        }
                        break;

                    case 'tool-input-end':
                        // Forwarded on the full stream so transforms/consumers
                        // see it, but deliberately NOT surfaced to onChunk and
                        // dropped by the serializer — wire completion is signaled
                        // by tool-call → tool-input-available (Vercel parity).
                        yield array_merge($chunk, ['step' => $step]);
                        break;

                    case 'finish':
                        $finishReason = $chunk['finishReason'] ?? null;
                        $usage = $chunk['usage'] ?? null;
                        // Don't yield step-finish here — defer until after
                        // tool execution so tool-result events fall within
                        // the step (required by Data Stream Protocol).
                        break;

                    default:
                        yield array_merge($chunk, ['step' => $step]);
                        break;
                }
            }

            // Handle pending usage
            if ($usage instanceof LanguageModelUsage) {
                $totalUsage = $totalUsage->add($usage);
            }

            // Execute tool calls if any — unless a human has to decide first.
            $toolResults = [];
            $awaitingApproval = false;
            if (!empty($toolCalls) && $this->tools !== null) {
                foreach ($toolCalls as $toolCallData) {
                    $toolCall = ToolCall::fromContentPart($toolCallData);

                    // Asked before execution, never after: a tool that needs
                    // approval must not run and ask later. Ungated calls in the
                    // same step still run — only the gated one is held.
                    $approval = ToolPreparer::approvalFor($toolCall, $this->tools);
                    if ($approval !== null) {
                        $awaitingApproval = true;
                        yield array_merge($approval->toStreamChunk(), ['step' => $step]);
                        continue;
                    }

                    $toolResult = ToolPreparer::executeToolCall($toolCall, $this->tools);

                    if ($toolResult !== null) {
                        $toolResults[] = $toolResult;
                        yield [
                            'type' => 'tool-result',
                            'toolCallId' => $toolResult->toolCallId,
                            'toolName' => $toolResult->toolName,
                            'result' => $toolResult->output,
                            'step' => $step,
                        ];
                    }
                }
            }

            // Yield step-finish AFTER tool execution so that
            // tool-result events fall within the step.
            yield [
                'type' => 'step-finish',
                'step' => $step,
                'finishReason' => $finishReason,
                'usage' => $usage,
            ];

            // Notify step finish
            if ($this->onStepFinish !== null) {
                ($this->onStepFinish)([
                    'text' => $textAccumulator,
                    'toolCalls' => $toolCalls,
                    'toolResults' => $toolResults,
                    'step' => $step,
                ]);
            }

            // A call waiting on a human ends the run. There is nothing to feed
            // the model, and letting it take another step would have it narrate
            // a decision nobody has made yet — the turn should end quietly with
            // the request on screen.
            if ($awaitingApproval) {
                break;
            }

            // Continue with tool results in next step?
            if (!empty($toolResults) && $step < $this->maxSteps - 1) {
                $currentMessages[] = Message::assistant(
                    array_map(fn(ToolCall $tc) => $tc->toArray(),
                        array_map(fn($tcd) => ToolCall::fromContentPart($tcd), $toolCalls))
                );
                $currentMessages[] = Message::tool(
                    array_map(fn(ToolResult $tr) => $tr->toMessagePart(), $toolResults)
                );
            } else {
                break;
            }
        }

        yield [
            'type' => 'finish',
            'totalUsage' => $totalUsage,
        ];
    }

    /**
     * Settle approval decisions carried in the incoming messages.
     *
     * A run stopped by {@see ToolPreparer::approvalFor()} leaves a tool call
     * unresolved. When the decision comes back, `convertToModelMessages()`
     * replays it as a `tool-approval-response` part; this turns each one into a
     * real `tool-result` before the first model call, so no provider ever sees
     * the approval protocol.
     *
     * Yields the stream chunks the client needs to close the card it is still
     * showing, and returns the rewritten messages via `getReturn()`.
     *
     * Every branch produces a `tool-result` part, including the failures. An
     * assistant `tool-call` left without one is a hard 400 from OpenAI, so
     * "could not settle this" has to still resolve the call — saying so in the
     * result the model reads.
     *
     * @param Message[] $messages
     * @return \Generator<int, array, mixed, Message[]>
     */
    private function settleApprovals(array $messages): \Generator
    {
        $settled = [];

        foreach ($messages as $message) {
            if ($message->role !== 'tool' || !is_array($message->content)) {
                $settled[] = $message;
                continue;
            }

            $content = [];
            $rewritten = false;

            foreach ($message->content as $part) {
                if (!is_array($part) || ($part['type'] ?? '') !== 'tool-approval-response') {
                    $content[] = $part;
                    continue;
                }

                $rewritten = true;

                $toolCallId = (string) ($part['toolCallId'] ?? '');
                $toolName = (string) ($part['toolName'] ?? '');
                $approved = (bool) ($part['approved'] ?? false);
                $reason = isset($part['reason']) ? (string) $part['reason'] : null;
                $input = $part['input'] ?? [];
                $tool = $this->tools[$toolName] ?? null;

                if ($toolCallId === '') {
                    // Nothing to resolve and nothing to patch on the client: the
                    // part cannot be tied back to a call. Drop it rather than
                    // inventing a result for a call we cannot name.
                    continue;
                }

                if (!$approved) {
                    $text = $reason !== null && $reason !== ''
                        ? 'The user denied this tool call: ' . $reason
                        : 'The user denied this tool call.';

                    yield [
                        'type' => 'tool-output-denied',
                        'toolCallId' => $toolCallId,
                        'reason' => $reason,
                    ];

                    $content[] = [
                        'type' => 'tool-result',
                        'toolCallId' => $toolCallId,
                        'result' => $text,
                    ];
                    continue;
                }

                if ($tool === null || $tool->execute === null) {
                    // Approved, but the tool is gone — renamed, or not resolved
                    // for this request. The human's decision cannot be honoured,
                    // and pretending otherwise would be worse than saying so.
                    $text = sprintf('Tool "%s" is no longer available, so the approved call did not run.', $toolName);

                    yield [
                        'type' => 'tool-output-error',
                        'toolCallId' => $toolCallId,
                        'errorText' => $text,
                    ];

                    $content[] = [
                        'type' => 'tool-result',
                        'toolCallId' => $toolCallId,
                        'result' => $text,
                    ];
                    continue;
                }

                $toolResult = ToolPreparer::executeToolCall(
                    new ToolCall(
                        toolCallId: $toolCallId,
                        toolName: $toolName,
                        input: $input,
                    ),
                    $this->tools,
                    // What released the call, handed to the tool so a consumer
                    // can redeem the approval it recorded when it asked.
                    array_filter([
                        'id' => $part['approvalId'] ?? null,
                        'approved' => true,
                        'reason' => $reason,
                    ], fn($v) => $v !== null),
                );

                yield [
                    'type' => 'tool-result',
                    'toolCallId' => $toolResult->toolCallId,
                    'toolName' => $toolResult->toolName,
                    'result' => $toolResult->output,
                ];

                $content[] = $toolResult->toMessagePart();
            }

            // Untouched messages pass through as they are, keeping whatever
            // provider options they carried.
            if (!$rewritten) {
                $settled[] = $message;
                continue;
            }

            // A tool message emptied by the drop above would be an empty
            // `role: tool` turn, which providers reject.
            if (!empty($content)) {
                $settled[] = new Message('tool', $content, $message->providerOptions);
            }
        }

        return $settled;
    }
}
