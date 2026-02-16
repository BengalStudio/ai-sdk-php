<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Prompt\CallSettings;
use BengalStudio\AI\Prompt\PromptConverter;
use BengalStudio\AI\Types\LanguageModelCallOptions;
use BengalStudio\AI\Types\LanguageModelUsage;
use BengalStudio\AI\Types\Message;
use BengalStudio\AI\Util\Retry;

/**
 * Stream a structured object for a given prompt using a language model.
 *
 * Mirrors Vercel AI SDK's streamObject function.
 */
class StreamObject
{
    private LanguageModel $model;
    private array $schema;
    private ?string $schemaName = null;
    private ?string $schemaDescription = null;
    private ?string $prompt = null;
    private ?string $system = null;
    private ?array $messages = null;
    private string $mode = 'json';
    private int $maxRetries = 2;
    private array $settings = [];
    private ?array $providerOptions = null;
    private ?\Closure $onFinish = null;

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
     * Execute the streaming object generation.
     */
    public function execute(): StreamObjectResult
    {
        $promptMessages = PromptConverter::standardize(
            prompt: $this->prompt,
            system: $this->system,
            messages: $this->messages,
        );

        $validatedSettings = CallSettings::prepare($this->settings);

        return new StreamObjectResult(
            streamFactory: fn() => $this->createStream($promptMessages, $validatedSettings),
            schema: $this->schema,
            onFinish: $this->onFinish,
        );
    }

    private function createStream(array $promptMessages, array $validatedSettings): \Generator
    {
        if ($this->mode === 'tool') {
            yield from $this->createToolModeStream($promptMessages, $validatedSettings);
        } else {
            yield from $this->createJsonModeStream($promptMessages, $validatedSettings);
        }
    }

    private function createJsonModeStream(array $promptMessages, array $validatedSettings): \Generator
    {
        $schemaInstruction = "Respond with a JSON object that conforms to the following JSON Schema:\n\n";
        $schemaInstruction .= json_encode($this->schema, JSON_PRETTY_PRINT);

        if ($this->schemaName) {
            $schemaInstruction = "Generate a {$this->schemaName}. " . $schemaInstruction;
        }

        array_unshift($promptMessages, Message::system($schemaInstruction));

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

        $streamResult = Retry::execute(
            fn() => $this->model->doStream($options),
            maxRetries: $this->maxRetries,
        );

        $jsonAccumulator = '';

        foreach ($streamResult->getStream() as $chunk) {
            $type = $chunk['type'] ?? 'unknown';

            if ($type === 'text-delta') {
                $delta = $chunk['textDelta'] ?? '';
                $jsonAccumulator .= $delta;

                // Attempt partial parse
                $partial = $this->tryParsePartialJson($jsonAccumulator);

                yield [
                    'type' => 'object-delta',
                    'textDelta' => $delta,
                    'partialObject' => $partial,
                ];
            } elseif ($type === 'finish') {
                yield [
                    'type' => 'finish',
                    'finishReason' => $chunk['finishReason'] ?? null,
                    'usage' => $chunk['usage'] ?? null,
                ];
            }
        }
    }

    private function createToolModeStream(array $promptMessages, array $validatedSettings): \Generator
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

        $streamResult = Retry::execute(
            fn() => $this->model->doStream($options),
            maxRetries: $this->maxRetries,
        );

        $argsAccumulator = '';

        foreach ($streamResult->getStream() as $chunk) {
            $type = $chunk['type'] ?? 'unknown';

            if ($type === 'tool-call-delta') {
                $delta = $chunk['argsTextDelta'] ?? '';
                $argsAccumulator .= $delta;

                $partial = $this->tryParsePartialJson($argsAccumulator);

                yield [
                    'type' => 'object-delta',
                    'textDelta' => $delta,
                    'partialObject' => $partial,
                ];
            } elseif ($type === 'tool-call') {
                $args = $chunk['args'] ?? [];
                if (is_string($args)) {
                    $args = json_decode($args, true) ?? [];
                }

                yield [
                    'type' => 'object',
                    'object' => $args,
                ];
            } elseif ($type === 'finish') {
                yield [
                    'type' => 'finish',
                    'finishReason' => $chunk['finishReason'] ?? null,
                    'usage' => $chunk['usage'] ?? null,
                ];
            }
        }
    }

    /**
     * Attempt to parse potentially incomplete JSON.
     *
     * @return array|null
     */
    private function tryParsePartialJson(string $json): ?array
    {
        // Try parsing as-is first
        $result = json_decode($json, true);
        if ($result !== null) {
            return $result;
        }

        // Try to close open braces/brackets to make it valid JSON
        $openBraces = substr_count($json, '{') - substr_count($json, '}');
        $openBrackets = substr_count($json, '[') - substr_count($json, ']');

        $attempt = $json;

        // Close unclosed strings
        $inString = false;
        $escaped = false;
        for ($i = 0; $i < strlen($json); $i++) {
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($json[$i] === '\\') {
                $escaped = true;
                continue;
            }
            if ($json[$i] === '"') {
                $inString = !$inString;
            }
        }

        if ($inString) {
            $attempt .= '"';
        }

        // Remove trailing comma
        $attempt = preg_replace('/,\s*$/', '', $attempt);

        // Close open brackets/braces
        for ($i = 0; $i < $openBrackets; $i++) {
            $attempt .= ']';
        }
        for ($i = 0; $i < $openBraces; $i++) {
            $attempt .= '}';
        }

        $result = json_decode($attempt, true);
        return $result;
    }
}
