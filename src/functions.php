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
use BengalStudio\AI\Core\StreamObject;
use BengalStudio\AI\Core\StreamObjectResult;
use BengalStudio\AI\Core\StreamText;
use BengalStudio\AI\Core\StreamTextResult;
use BengalStudio\AI\Registry\CustomProvider;
use BengalStudio\AI\Registry\ProviderRegistry;
use BengalStudio\AI\Tool\Tool;

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

    return $stream->execute();
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
