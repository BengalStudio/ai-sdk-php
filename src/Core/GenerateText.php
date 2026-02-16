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
use BengalStudio\AI\Types\FinishReason;
use BengalStudio\AI\Types\LanguageModelCallOptions;
use BengalStudio\AI\Types\LanguageModelUsage;
use BengalStudio\AI\Types\Message;
use BengalStudio\AI\Util\Retry;

/**
 * Generate text and call tools for a given prompt using a language model.
 *
 * This function does not stream the output.
 * If you want to stream the output, use streamText() instead.
 *
 * Mirrors Vercel AI SDK's generateText function.
 */
class GenerateText
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
    private ?\Closure $onStepFinish = null;

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

    public function onStepFinish(\Closure $callback): self
    {
        $this->onStepFinish = $callback;
        return $this;
    }

    /**
     * Execute the text generation.
     */
    public function execute(): GenerateTextResult
    {
        $promptMessages = PromptConverter::standardize(
            prompt: $this->prompt,
            system: $this->system,
            messages: $this->messages,
        );

        $validatedSettings = CallSettings::prepare($this->settings);
        $prepared = ToolPreparer::prepare($this->tools, $this->toolChoice);

        $steps = [];
        $totalUsage = new LanguageModelUsage();
        $currentMessages = $promptMessages;

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

            $result = Retry::execute(
                fn() => $this->model->doGenerate($options),
                maxRetries: $this->maxRetries,
            );

            $totalUsage = $totalUsage->add($result->usage);

            // Extract tool calls and execute them
            $toolCalls = $result->getToolCalls();
            $toolResults = [];

            if (!empty($toolCalls) && $this->tools !== null) {
                foreach ($toolCalls as $toolCallData) {
                    $toolCall = ToolCall::fromContentPart($toolCallData);
                    $toolResult = ToolPreparer::executeToolCall($toolCall, $this->tools);

                    if ($toolResult !== null) {
                        $toolResults[] = $toolResult;
                    }
                }
            }

            $stepResult = new StepResult(
                text: $result->getText(),
                toolCalls: array_map(fn($tc) => ToolCall::fromContentPart($tc), $toolCalls),
                toolResults: $toolResults,
                finishReason: FinishReason::tryFrom($result->finishReason) ?? FinishReason::Unknown,
                usage: $result->usage,
                warnings: $result->warnings ?? [],
                response: $result->response,
                content: $result->content,
            );

            $steps[] = $stepResult;

            if ($this->onStepFinish !== null) {
                ($this->onStepFinish)($stepResult);
            }

            // If there are tool results, continue with tool results in next step
            if (!empty($toolResults) && $step < $this->maxSteps - 1) {
                // Add assistant message with tool calls
                $currentMessages[] = Message::assistant(
                    array_map(fn(ToolCall $tc) => $tc->toArray(), $stepResult->toolCalls)
                );

                // Add tool result messages
                $currentMessages[] = Message::tool(
                    array_map(fn(ToolResult $tr) => $tr->toMessagePart(), $toolResults)
                );
            } else {
                break;
            }
        }

        $generateResult = new GenerateTextResult(
            steps: $steps,
            totalUsage: $totalUsage,
        );

        if ($this->onFinish !== null) {
            ($this->onFinish)($generateResult);
        }

        return $generateResult;
    }
}
