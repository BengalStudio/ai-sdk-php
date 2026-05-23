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
        $currentMessages = $promptMessages;
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

                    case 'tool-call-delta':
                        yield array_merge($chunk, ['step' => $step]);
                        if ($this->onChunk !== null) {
                            ($this->onChunk)($chunk);
                        }
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

            // Execute tool calls if any
            $toolResults = [];
            if (!empty($toolCalls) && $this->tools !== null) {
                foreach ($toolCalls as $toolCallData) {
                    $toolCall = ToolCall::fromContentPart($toolCallData);
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
}
