<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Exceptions\NoObjectGeneratedException;
use BengalStudio\AI\Prompt\CallSettings;
use BengalStudio\AI\Prompt\PromptConverter;
use BengalStudio\AI\Types\FinishReason;
use BengalStudio\AI\Types\LanguageModelCallOptions;
use BengalStudio\AI\Util\Retry;

/**
 * Generate a structured object for a given prompt using a language model.
 *
 * This function does not stream the output.
 * If you want to stream the output, use streamObject() instead.
 *
 * Mirrors Vercel AI SDK's generateObject function.
 */
class GenerateObject
{
    private LanguageModel $model;
    private array $schema;
    private ?string $schemaName = null;
    private ?string $schemaDescription = null;
    private ?string $prompt = null;
    private ?string $system = null;
    private ?array $messages = null;
    private string $mode = 'json'; // 'json' or 'tool'
    private int $maxRetries = 2;
    private array $settings = [];
    private ?array $providerOptions = null;
    private ?\Closure $onFinish = null;

    /**
     * @param LanguageModel $model
     * @param array $schema JSON Schema definition for the expected object structure.
     */
    public function __construct(LanguageModel $model, array $schema)
    {
        $this->model = $model;
        $this->schema = $schema;
    }

    public function schemaName(string $name): self
    {
        $this->schemaName = $name;
        return $this;
    }

    public function schemaDescription(string $description): self
    {
        $this->schemaDescription = $description;
        return $this;
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

    public function messages(array $messages): self
    {
        $this->messages = $messages;
        return $this;
    }

    /**
     * Generation mode: 'json' uses responseFormat: json_object;
     * 'tool' uses a tool call to generate structured output.
     */
    public function mode(string $mode): self
    {
        $this->mode = $mode;
        return $this;
    }

    public function maxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
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

    /**
     * Execute the object generation.
     *
     * @throws NoObjectGeneratedException
     */
    public function execute(): GenerateObjectResult
    {
        $promptMessages = PromptConverter::standardize(
            prompt: $this->prompt,
            system: $this->system,
            messages: $this->messages,
        );

        $validatedSettings = CallSettings::prepare($this->settings);

        if ($this->mode === 'tool') {
            return $this->executeWithToolMode($promptMessages, $validatedSettings);
        }

        return $this->executeWithJsonMode($promptMessages, $validatedSettings);
    }

    private function executeWithJsonMode(array $promptMessages, array $validatedSettings): GenerateObjectResult
    {
        // Inject schema instruction into system prompt
        $schemaInstruction = $this->buildSchemaInstruction();

        // Prepend schema instruction as system message
        array_unshift($promptMessages, \BengalStudio\AI\Types\Message::system($schemaInstruction));

        $options = new LanguageModelCallOptions(
            prompt: $promptMessages,
            maxOutputTokens: $validatedSettings['maxOutputTokens'] ?? null,
            temperature: $validatedSettings['temperature'] ?? null,
            topP: $validatedSettings['topP'] ?? null,
            topK: $validatedSettings['topK'] ?? null,
            seed: $validatedSettings['seed'] ?? null,
            providerOptions: $this->providerOptions,
            responseFormat: ['type' => 'json', 'schema' => $this->schema],
        );

        $result = Retry::execute(
            fn() => $this->model->doGenerate($options),
            maxRetries: $this->maxRetries,
        );

        $text = $result->getText();
        $object = $this->parseJsonResponse($text);

        $generateResult = new GenerateObjectResult(
            object: $object,
            rawText: $text,
            finishReason: FinishReason::tryFrom($result->finishReason) ?? FinishReason::Unknown,
            usage: $result->usage,
            warnings: $result->warnings ?? [],
            response: $result->response,
        );

        if ($this->onFinish !== null) {
            ($this->onFinish)($generateResult);
        }

        return $generateResult;
    }

    private function executeWithToolMode(array $promptMessages, array $validatedSettings): GenerateObjectResult
    {
        $toolName = $this->schemaName ?? 'json';
        $toolDescription = $this->schemaDescription ?? 'Respond with a JSON object.';

        $options = new LanguageModelCallOptions(
            prompt: $promptMessages,
            maxOutputTokens: $validatedSettings['maxOutputTokens'] ?? null,
            temperature: $validatedSettings['temperature'] ?? null,
            topP: $validatedSettings['topP'] ?? null,
            topK: $validatedSettings['topK'] ?? null,
            seed: $validatedSettings['seed'] ?? null,
            providerOptions: $this->providerOptions,
            tools: [
                [
                    'type' => 'function',
                    'name' => $toolName,
                    'description' => $toolDescription,
                    'parameters' => $this->schema,
                ],
            ],
            toolChoice: ['type' => 'tool', 'toolName' => $toolName],
        );

        $result = Retry::execute(
            fn() => $this->model->doGenerate($options),
            maxRetries: $this->maxRetries,
        );

        $toolCalls = $result->getToolCalls();

        if (empty($toolCalls)) {
            throw new NoObjectGeneratedException(
                'No structured object was generated. The model did not make a tool call.'
            );
        }

        $object = $toolCalls[0]['args'] ?? $toolCalls[0]['input'] ?? [];
        if (is_string($object)) {
            $object = json_decode($object, true) ?? [];
        }

        $generateResult = new GenerateObjectResult(
            object: $object,
            rawText: json_encode($object),
            finishReason: FinishReason::tryFrom($result->finishReason) ?? FinishReason::Unknown,
            usage: $result->usage,
            warnings: $result->warnings ?? [],
            response: $result->response,
        );

        if ($this->onFinish !== null) {
            ($this->onFinish)($generateResult);
        }

        return $generateResult;
    }

    private function buildSchemaInstruction(): string
    {
        $instruction = "Respond with a JSON object that conforms to the following JSON Schema:\n\n";
        $instruction .= json_encode($this->schema, JSON_PRETTY_PRINT);

        if ($this->schemaName) {
            $instruction = "Generate a {$this->schemaName}. " . $instruction;
        }

        if ($this->schemaDescription) {
            $instruction .= "\n\n" . $this->schemaDescription;
        }

        return $instruction;
    }

    /**
     * @throws NoObjectGeneratedException
     */
    private function parseJsonResponse(string $text): array
    {
        // Strip markdown code fences if present
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);

        $object = json_decode($cleaned, true);

        if ($object === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new NoObjectGeneratedException(
                "Failed to parse JSON response: " . json_last_error_msg() . "\nRaw text: " . $text
            );
        }

        return $object;
    }
}
