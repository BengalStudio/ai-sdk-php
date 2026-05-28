<?php

declare(strict_types=1);

namespace BengalStudio\AI;

use BengalStudio\AI\Contracts\EmbeddingModel;
use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Core\Embed;
use BengalStudio\AI\Core\EmbedMany;
use BengalStudio\AI\Core\EmbedManyResult;
use BengalStudio\AI\Core\EmbedResult;
use BengalStudio\AI\Core\GenerateObject;
use BengalStudio\AI\Core\GenerateObjectResult;
use BengalStudio\AI\Core\GenerateText;
use BengalStudio\AI\Core\GenerateTextResult;
use BengalStudio\AI\Core\SmoothStream;
use BengalStudio\AI\Core\StreamObject;
use BengalStudio\AI\Core\StreamObjectResult;
use BengalStudio\AI\Core\StreamText;
use BengalStudio\AI\Core\StreamTextResult;
use BengalStudio\AI\Prompt\UIMessage;
use BengalStudio\AI\Registry\CustomProvider;
use BengalStudio\AI\Registry\ProviderRegistry;
use BengalStudio\AI\Tool\Tool;
use BengalStudio\AI\Types\Message;

/**
 * Generate text and call tools for a given prompt using a language model.
 *
 * @param array $options {
 *   @type LanguageModel $model       Required. The language model to use.
 *   @type string        $prompt      The text prompt.
 *   @type string        $system      The system instruction.
 *   @type array         $messages    Array of Message objects.
 *   @type array         $tools       Map of tool name => Tool definition.
 *   @type mixed         $toolChoice  Tool choice constraint.
 *   @type int           $maxRetries  Max retries on failure (default: 2).
 *   @type int           $maxSteps    Max multi-step tool execution rounds (default: 1).
 *   @type int           $maxOutputTokens  Max tokens to generate.
 *   @type float         $temperature Sampling temperature.
 *   @type float         $topP        Top-p sampling.
 *   @type int           $topK        Top-k sampling.
 *   @type float         $frequencyPenalty  Frequency penalty.
 *   @type float         $presencePenalty   Presence penalty.
 *   @type array         $stopSequences    Stop sequences.
 *   @type int           $seed        Random seed.
 *   @type array         $providerOptions  Provider-specific options.
 *   @type \Closure      $onFinish    Callback when generation completes.
 *   @type \Closure      $onStepFinish Callback when a step completes.
 * }
 */
function generateText(array $options): GenerateTextResult
{
    $generator = new GenerateText($options['model']);

    if (isset($options['prompt'])) $generator->prompt($options['prompt']);
    if (isset($options['system'])) $generator->system($options['system']);
    if (isset($options['messages'])) $generator->messages($options['messages']);
    if (isset($options['tools'])) $generator->tools($options['tools']);
    if (isset($options['toolChoice'])) $generator->toolChoice($options['toolChoice']);
    if (isset($options['maxRetries'])) $generator->maxRetries($options['maxRetries']);
    if (isset($options['maxSteps'])) $generator->maxSteps($options['maxSteps']);
    if (isset($options['maxOutputTokens'])) $generator->maxOutputTokens($options['maxOutputTokens']);
    if (isset($options['temperature'])) $generator->temperature($options['temperature']);
    if (isset($options['topP'])) $generator->topP($options['topP']);
    if (isset($options['topK'])) $generator->topK($options['topK']);
    if (isset($options['frequencyPenalty'])) $generator->frequencyPenalty($options['frequencyPenalty']);
    if (isset($options['presencePenalty'])) $generator->presencePenalty($options['presencePenalty']);
    if (isset($options['stopSequences'])) $generator->stopSequences($options['stopSequences']);
    if (isset($options['seed'])) $generator->seed($options['seed']);
    if (isset($options['providerOptions'])) $generator->providerOptions($options['providerOptions']);
    if (isset($options['onFinish'])) $generator->onFinish($options['onFinish']);
    if (isset($options['onStepFinish'])) $generator->onStepFinish($options['onStepFinish']);

    return $generator->execute();
}

/**
 * Stream text and call tools for a given prompt using a language model.
 *
 * @param array $options Same options as generateText, plus:
 *   @type \Closure $onChunk Callback for each streamed chunk.
 */
function streamText(array $options): StreamTextResult
{
    $stream = new StreamText($options['model']);

    if (isset($options['prompt'])) $stream->prompt($options['prompt']);
    if (isset($options['system'])) $stream->system($options['system']);
    if (isset($options['messages'])) $stream->messages($options['messages']);
    if (isset($options['tools'])) $stream->tools($options['tools']);
    if (isset($options['toolChoice'])) $stream->toolChoice($options['toolChoice']);
    if (isset($options['maxRetries'])) $stream->maxRetries($options['maxRetries']);
    if (isset($options['maxSteps'])) $stream->maxSteps($options['maxSteps']);
    if (isset($options['maxOutputTokens'])) $stream->maxOutputTokens($options['maxOutputTokens']);
    if (isset($options['temperature'])) $stream->temperature($options['temperature']);
    if (isset($options['topP'])) $stream->topP($options['topP']);
    if (isset($options['topK'])) $stream->topK($options['topK']);
    if (isset($options['frequencyPenalty'])) $stream->frequencyPenalty($options['frequencyPenalty']);
    if (isset($options['presencePenalty'])) $stream->presencePenalty($options['presencePenalty']);
    if (isset($options['stopSequences'])) $stream->stopSequences($options['stopSequences']);
    if (isset($options['seed'])) $stream->seed($options['seed']);
    if (isset($options['providerOptions'])) $stream->providerOptions($options['providerOptions']);
    if (isset($options['onFinish'])) $stream->onFinish($options['onFinish']);
    if (isset($options['onChunk'])) $stream->onChunk($options['onChunk']);
    if (isset($options['onStepFinish'])) $stream->onStepFinish($options['onStepFinish']);
    if (isset($options['experimental_transform'])) $stream->transform($options['experimental_transform']);

    return $stream->execute();
}

/**
 * Build a transform that smooths text and reasoning streaming output.
 *
 * Returns a generator-to-generator closure suitable for passing to
 * `streamText()`'s `experimental_transform` option (or `StreamText::transform()`).
 *
 * @param array{
 *   delayInMs?: int|null,
 *   chunking?: 'word'|'line'|string|callable|\IntlBreakIterator,
 *   _internal?: array{ delay?: callable }
 * } $options
 *
 * @return \Closure(\Generator): \Generator
 *
 * @example
 * ```php
 * use function BengalStudio\AI\{smoothStream, streamText};
 *
 * $result = streamText([
 *     'model'                  => $model,
 *     'prompt'                 => '...',
 *     'experimental_transform' => smoothStream(['delayInMs' => 20, 'chunking' => 'word']),
 * ]);
 * ```
 */
function smoothStream(array $options = []): \Closure
{
    return SmoothStream::create($options);
}

/**
 * Generate a structured object for a given prompt using a language model.
 *
 * @param array $options {
 *   @type LanguageModel $model   Required. The language model to use.
 *   @type array         $schema  Required. JSON Schema array defining the structure.
 *   @type string        $prompt  The text prompt.
 *   @type string        $system  The system instruction.
 *   @type array         $messages Array of Message objects.
 *   @type string        $schemaName  Name for the schema (used in tool mode).
 *   @type string        $schemaDescription  Description for the schema.
 *   @type string        $mode    Generation mode: 'json' or 'tool' (default: 'json').
 *   @type int           $maxRetries  Max retries.
 *   @type \Closure      $onFinish Callback when generation completes.
 * }
 */
function generateObject(array $options): GenerateObjectResult
{
    $generator = new GenerateObject($options['model'], $options['schema']);

    if (isset($options['prompt'])) $generator->prompt($options['prompt']);
    if (isset($options['system'])) $generator->system($options['system']);
    if (isset($options['messages'])) $generator->messages($options['messages']);
    if (isset($options['schemaName'])) $generator->schemaName($options['schemaName']);
    if (isset($options['schemaDescription'])) $generator->schemaDescription($options['schemaDescription']);
    if (isset($options['mode'])) $generator->mode($options['mode']);
    if (isset($options['maxRetries'])) $generator->maxRetries($options['maxRetries']);
    if (isset($options['maxOutputTokens'])) $generator->maxOutputTokens($options['maxOutputTokens']);
    if (isset($options['temperature'])) $generator->temperature($options['temperature']);
    if (isset($options['topP'])) $generator->topP($options['topP']);
    if (isset($options['topK'])) $generator->topK($options['topK']);
    if (isset($options['seed'])) $generator->seed($options['seed']);
    if (isset($options['providerOptions'])) $generator->providerOptions($options['providerOptions']);
    if (isset($options['onFinish'])) $generator->onFinish($options['onFinish']);

    return $generator->execute();
}

/**
 * Stream a structured object for a given prompt using a language model.
 *
 * @param array $options Same options as generateObject.
 */
function streamObject(array $options): StreamObjectResult
{
    $stream = new StreamObject($options['model'], $options['schema']);

    if (isset($options['prompt'])) $stream->prompt($options['prompt']);
    if (isset($options['system'])) $stream->system($options['system']);
    if (isset($options['messages'])) $stream->messages($options['messages']);
    if (isset($options['schemaName'])) $stream->schemaName($options['schemaName']);
    if (isset($options['schemaDescription'])) $stream->schemaDescription($options['schemaDescription']);
    if (isset($options['mode'])) $stream->mode($options['mode']);
    if (isset($options['maxRetries'])) $stream->maxRetries($options['maxRetries']);
    if (isset($options['maxOutputTokens'])) $stream->maxOutputTokens($options['maxOutputTokens']);
    if (isset($options['temperature'])) $stream->temperature($options['temperature']);
    if (isset($options['topP'])) $stream->topP($options['topP']);
    if (isset($options['topK'])) $stream->topK($options['topK']);
    if (isset($options['seed'])) $stream->seed($options['seed']);
    if (isset($options['providerOptions'])) $stream->providerOptions($options['providerOptions']);
    if (isset($options['onFinish'])) $stream->onFinish($options['onFinish']);

    return $stream->execute();
}

/**
 * Embed a single value using an embedding model.
 *
 * @param array $options {
 *   @type EmbeddingModel $model Required. The embedding model to use.
 *   @type mixed          $value Required. The value to embed.
 *   @type int            $maxRetries Max retries.
 *   @type array          $headers Custom headers.
 *   @type array          $providerOptions Provider-specific options.
 * }
 */
function embed(array $options): EmbedResult
{
    $embedder = new Embed($options['model'], $options['value']);

    if (isset($options['maxRetries'])) $embedder->maxRetries($options['maxRetries']);
    if (isset($options['headers'])) $embedder->headers($options['headers']);
    if (isset($options['providerOptions'])) $embedder->providerOptions($options['providerOptions']);

    return $embedder->execute();
}

/**
 * Embed multiple values using an embedding model.
 *
 * Automatically handles chunking if values exceed the model's max per call.
 *
 * @param array $options {
 *   @type EmbeddingModel $model  Required. The embedding model to use.
 *   @type array          $values Required. Array of values to embed.
 *   @type int            $maxRetries Max retries.
 *   @type array          $headers Custom headers.
 *   @type array          $providerOptions Provider-specific options.
 * }
 */
function embedMany(array $options): EmbedManyResult
{
    $embedder = new EmbedMany($options['model'], $options['values']);

    if (isset($options['maxRetries'])) $embedder->maxRetries($options['maxRetries']);
    if (isset($options['headers'])) $embedder->headers($options['headers']);
    if (isset($options['providerOptions'])) $embedder->providerOptions($options['providerOptions']);

    return $embedder->execute();
}

/**
 * Define a tool for use with generateText or streamText.
 *
 * @param array $options {
 *   @type string   $description Human-readable tool description.
 *   @type array    $parameters  JSON Schema for input parameters.
 *   @type \Closure $execute     Function that executes the tool: fn(array $args) => mixed
 * }
 */
function tool(array $options): Tool
{
    return Tool::define(
        description: $options['description'] ?? '',
        inputSchema: $options['parameters'] ?? [],
        execute: $options['execute'] ?? null,
    );
}

/**
 * Create a provider registry for resolving models by ID.
 *
 * The registry allows using string IDs like 'openai:gpt-4o' to
 * reference models from registered providers.
 *
 * @param array<string, \BengalStudio\AI\Contracts\Provider> $providers
 */
function createProviderRegistry(array $providers): ProviderRegistry
{
    return new ProviderRegistry($providers);
}

/**
 * Create a custom provider with explicit model mappings.
 *
 * @param array $options {
 *   @type array<string, LanguageModel>  $languageModels  Map of model ID => LanguageModel.
 *   @type array<string, EmbeddingModel> $embeddingModels Map of model ID => EmbeddingModel.
 *   @type \BengalStudio\AI\Contracts\Provider|null $fallbackProvider Fallback provider.
 * }
 */
function customProvider(array $options): CustomProvider
{
    return new CustomProvider(
        languageModels: $options['languageModels'] ?? [],
        embeddingModels: $options['embeddingModels'] ?? [],
        fallbackProvider: $options['fallbackProvider'] ?? null,
    );
}

/**
 * Calculate cosine similarity between two embedding vectors.
 *
 * @param float[] $a First embedding vector.
 * @param float[] $b Second embedding vector.
 * @return float Cosine similarity between -1 and 1.
 */
function cosineSimilarity(array $a, array $b): float
{
    if (count($a) !== count($b) || empty($a)) {
        return 0.0;
    }

    $dotProduct = 0.0;
    $normA = 0.0;
    $normB = 0.0;

    for ($i = 0, $len = count($a); $i < $len; $i++) {
        $dotProduct += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }

    $normA = sqrt($normA);
    $normB = sqrt($normB);

    if ($normA === 0.0 || $normB === 0.0) {
        return 0.0;
    }

    return $dotProduct / ($normA * $normB);
}

/**
 * Convert UI messages from @ai-sdk/react to model messages for AI SDK PHP.
 *
 * This function takes the UIMessage format sent by the `useChat` hook
 * from `@ai-sdk/react` and converts it into the `Message` format that
 * `generateText()` and `streamText()` accept.
 *
 * Mirrors the TypeScript `convertToModelMessages()` function from the Vercel AI SDK.
 *
 * @param array $uiMessages Array of UIMessage arrays (decoded from JSON), or UIMessage objects.
 *                          Each message has: id, role, parts[{type, text, ...}].
 * @return Message[] Array of model messages ready for generateText()/streamText().
 *
 * @example
 * ```php
 * use function BengalStudio\AI\convertToModelMessages;
 * use function BengalStudio\AI\streamText;
 *
 * // In a WordPress REST API callback:
 * $input = $request->get_json_params();
 * $uiMessages = $input['messages'] ?? [];
 *
 * $result = streamText([
 *     'model'    => $model,
 *     'messages' => convertToModelMessages($uiMessages),
 * ]);
 *
 * $result->toUIMessageStreamResponse();
 * exit;
 * ```
 */
function convertToModelMessages(array $uiMessages): array
{
    $modelMessages = [];

    foreach ($uiMessages as $msg) {
        $uiMessage = $msg instanceof UIMessage
            ? $msg
            : UIMessage::fromArray($msg);

        switch ($uiMessage->role) {
            case 'system':
                $text = $uiMessage->getTextContent();
                if ($text !== '') {
                    $modelMessages[] = Message::system($text);
                }
                break;

            case 'user':
                $content = convertUserMessageContent($uiMessage);
                if ($content !== null) {
                    $modelMessages[] = Message::user($content);
                }
                break;

            case 'assistant':
                $result = convertAssistantMessage($uiMessage);
                foreach ($result as $message) {
                    $modelMessages[] = $message;
                }
                break;
        }
    }

    return $modelMessages;
}

/**
 * Convert a user UIMessage to model message content.
 *
 * Handles text parts and file/image parts (multi-modal messages).
 *
 * @param UIMessage $uiMessage
 * @return string|array|null Content for Message::user().
 *
 * @internal
 */
function convertUserMessageContent(UIMessage $uiMessage): string|array|null
{
    $textParts = $uiMessage->getPartsByType('text');
    $fileParts = $uiMessage->getPartsByType('file');

    // If no file parts, return simple text string
    if (empty($fileParts)) {
        $text = implode('', array_map(fn($p) => $p['text'] ?? '', $textParts));
        return $text !== '' ? $text : null;
    }

    // Multi-modal: return an array of content parts
    $content = [];

    foreach ($uiMessage->parts as $part) {
        $type = $part['type'] ?? '';

        if ($type === 'text' && ($part['text'] ?? '') !== '') {
            $content[] = ['type' => 'text', 'text' => $part['text']];
        } elseif ($type === 'file') {
            $mediaType = $part['mediaType'] ?? '';

            if (str_starts_with($mediaType, 'image/')) {
                $content[] = [
                    'type' => 'image',
                    'image' => $part['url'] ?? '',
                    'mimeType' => $mediaType,
                ];
            } else {
                $content[] = [
                    'type' => 'file',
                    'data' => $part['url'] ?? '',
                    'mimeType' => $mediaType,
                ];
            }
        }
    }

    return !empty($content) ? $content : null;
}

/**
 * Convert an assistant UIMessage to model messages.
 *
 * An assistant message may contain text and tool parts. AI SDK v5+ encodes
 * tool parts as `tool-<name>` (static) or `dynamic-tool`, with the call state
 * in `state` (`output-available` / `output-error` once resolved). Each
 * resolved tool part contributes a `tool-call` to the assistant message and a
 * `tool-result` to a separate `tool` role message, so the model sees what it
 * called and what came back on subsequent turns.
 *
 * @param UIMessage $uiMessage
 * @return Message[] One or more messages (assistant + optional tool results).
 *
 * @internal
 */
function convertAssistantMessage(UIMessage $uiMessage): array
{
    $messages = [];
    $assistantContent = [];
    $toolResults = [];

    foreach ($uiMessage->parts as $part) {
        $type = $part['type'] ?? '';

        if ($type === 'text' && ($part['text'] ?? '') !== '') {
            $assistantContent[] = [
                'type' => 'text',
                'text' => $part['text'],
            ];
        } elseif ($type === 'dynamic-tool' || str_starts_with($type, 'tool-')) {
            // Only replay tool calls that have resolved to a result. An
            // assistant tool-call with no matching tool message is a hard
            // OpenAI 400; incomplete states (input-streaming/input-available)
            // never reach persisted history, which is saved after the stream
            // finishes. Legacy v4 parts (state 'call'/'result') fall through
            // here too and are safely skipped.
            $state = $part['state'] ?? '';
            if ($state !== 'output-available' && $state !== 'output-error') {
                continue;
            }

            // Static tool parts carry the name in the type suffix
            // (`tool-<name>`); dynamic-tool parts carry an explicit field.
            $toolName = $type === 'dynamic-tool'
                ? ($part['toolName'] ?? '')
                : substr($type, strlen('tool-'));
            $toolCallId = $part['toolCallId'] ?? '';
            // `input` may be absent on output-error; fall back to rawInput.
            $input = $part['input'] ?? $part['rawInput'] ?? [];

            $assistantContent[] = [
                'type' => 'tool-call',
                'toolCallId' => $toolCallId,
                'toolName' => $toolName,
                'input' => $input,
            ];

            $output = $state === 'output-error'
                ? ($part['errorText'] ?? '')
                : ($part['output'] ?? '');

            $toolResults[] = [
                'type' => 'tool-result',
                'toolCallId' => $toolCallId,
                'toolName' => $toolName,
                'result' => is_string($output) ? $output : json_encode($output),
            ];
        }
    }

    // Build assistant message
    if (!empty($assistantContent)) {
        // If only text, use simple string form
        $hasNonText = false;
        foreach ($assistantContent as $c) {
            if ($c['type'] !== 'text') {
                $hasNonText = true;
                break;
            }
        }

        if (!$hasNonText) {
            $text = implode('', array_map(fn($c) => $c['text'], $assistantContent));
            $messages[] = Message::assistant($text);
        } else {
            $messages[] = Message::assistant($assistantContent);
        }
    }

    // Build tool result message
    if (!empty($toolResults)) {
        $messages[] = Message::tool($toolResults);
    }

    return $messages;
}
