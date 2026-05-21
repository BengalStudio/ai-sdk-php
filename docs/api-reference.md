# API Reference

Complete API reference for the `bengal-studio/ai-sdk` package.

## Table of Contents

- [Functions](#functions)
- [Contracts (Interfaces)](#contracts)
- [Types](#types)
- [Exceptions](#exceptions)
- [Result Objects](#result-objects)

---

## Functions

All functions are in the `BengalStudio\AI` namespace.

### `generateText(array $options): GenerateTextResult`

Generate text for a given prompt using a language model. Supports multi-step tool calling.

**Options:**

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `model` | `LanguageModel` | Yes | The language model to use |
| `prompt` | `string` | No* | Simple text prompt |
| `system` | `string` | No | System message |
| `messages` | `Message[]` | No* | Conversation messages |
| `tools` | `array<string, Tool>` | No | Available tools |
| `toolChoice` | `string\|array` | No | How to select tools (`'auto'`, `'none'`, `'required'`, or `['type' => 'tool', 'toolName' => '...']`) |
| `maxSteps` | `int` | No | Maximum tool-call steps (default: 1) |
| `maxRetries` | `int` | No | Retry count for transient errors (default: 2) |
| `maxOutputTokens` | `int` | No | Maximum tokens to generate |
| `temperature` | `float` | No | Sampling temperature (0–2) |
| `topP` | `float` | No | Nucleus sampling threshold |
| `topK` | `int` | No | Top-K sampling |
| `frequencyPenalty` | `float` | No | Frequency penalty (-2 to 2) |
| `presencePenalty` | `float` | No | Presence penalty (-2 to 2) |
| `stopSequences` | `string[]` | No | Stop generation sequences |
| `seed` | `int` | No | Random seed for reproducibility |
| `providerOptions` | `array` | No | Provider-specific options |
| `onFinish` | `callable` | No | Called when generation finishes |
| `onStepFinish` | `callable` | No | Called after each tool-call step |

*Either `prompt` or `messages` is required.

---

### `streamText(array $options): StreamTextResult`

Stream text output from a language model. Accepts all `generateText` options plus:

| Key | Type | Description |
|-----|------|-------------|
| `onChunk` | `callable` | Called for each streamed chunk |

---

### `generateObject(array $options): GenerateObjectResult`

Generate a structured object conforming to a JSON Schema.

**Options:**

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `model` | `LanguageModel` | Yes | The language model to use |
| `schema` | `array` | Yes | JSON Schema for the expected output |
| `prompt` | `string` | No* | Simple text prompt |
| `system` | `string` | No | System message |
| `messages` | `Message[]` | No* | Conversation messages |
| `schemaName` | `string` | No | Schema name for tool-based generation |
| `schemaDescription` | `string` | No | Schema description |
| `mode` | `string` | No | Generation mode: `'json'` or `'tool'` (default: `'json'`) |
| `maxRetries` | `int` | No | Retry count (default: 2) |
| `maxOutputTokens` | `int` | No | Maximum tokens to generate |
| `temperature` | `float` | No | Sampling temperature |
| `topP` | `float` | No | Nucleus sampling |
| `topK` | `int` | No | Top-K sampling |
| `seed` | `int` | No | Random seed |
| `providerOptions` | `array` | No | Provider-specific options |
| `onFinish` | `callable` | No | Called when generation finishes |

---

### `streamObject(array $options): StreamObjectResult`

Stream a structured object as it's being generated. Same options as `generateObject`.

---

### `embed(array $options): EmbedResult`

Embed a single value.

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `model` | `EmbeddingModel` | Yes | The embedding model |
| `value` | `string` | Yes | The value to embed |
| `maxRetries` | `int` | No | Retry count (default: 2) |
| `headers` | `array` | No | Additional HTTP headers |
| `providerOptions` | `array` | No | Provider-specific options |

---

### `embedMany(array $options): EmbedManyResult`

Embed multiple values. Automatically handles chunking based on the model's `maxEmbeddingsPerCall()`.

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `model` | `EmbeddingModel` | Yes | The embedding model |
| `values` | `string[]` | Yes | Values to embed |
| `maxRetries` | `int` | No | Retry count (default: 2) |
| `headers` | `array` | No | Additional HTTP headers |
| `providerOptions` | `array` | No | Provider-specific options |

---

### `tool(array $options): Tool`

Define a tool that a language model can call.

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `description` | `string` | Yes | What the tool does |
| `parameters` | `array` | Yes | JSON Schema for tool input |
| `execute` | `Closure` | No | Function to execute when called |

---

### `createProviderRegistry(array $providers): ProviderRegistry`

Create a provider registry for resolving models by `'provider:model'` string IDs.

```php
$registry = createProviderRegistry([
    'openai' => $openaiProvider,
    'anthropic' => $anthropicProvider,
]);
$model = $registry->languageModel('openai:gpt-4o');
```

---

### `customProvider(array $options): CustomProvider`

Create a custom provider with explicit model mappings.

| Key | Type | Description |
|-----|------|-------------|
| `languageModels` | `array<string, LanguageModel>` | Named language model map |
| `embeddingModels` | `array<string, EmbeddingModel>` | Named embedding model map |
| `fallbackProvider` | `Provider` | Fallback if model name is not in the map |

---

### `cosineSimilarity(array $a, array $b): float`

Calculate cosine similarity between two embedding vectors. Returns a float between -1 and 1.

---

## Contracts

### `LanguageModel`

Interface that all language model providers must implement.

```php
namespace BengalStudio\AI\Contracts;

interface LanguageModel
{
    public function specificationVersion(): string;
    public function provider(): string;
    public function modelId(): string;
    public function doGenerate(LanguageModelCallOptions $options): LanguageModelGenerateResult;
    public function doStream(LanguageModelCallOptions $options): LanguageModelStreamResult;
}
```

---

### `EmbeddingModel`

Interface for embedding model providers.

```php
namespace BengalStudio\AI\Contracts;

interface EmbeddingModel
{
    public function specificationVersion(): string;
    public function provider(): string;
    public function modelId(): string;
    public function maxEmbeddingsPerCall(): ?int;
    public function supportsParallelCalls(): bool;
    public function doEmbed(EmbeddingModelCallOptions $options): EmbeddingModelResult;
}
```

---

### `Provider`

Interface for a model provider (factory).

```php
namespace BengalStudio\AI\Contracts;

interface Provider
{
    public function languageModel(string $modelId): LanguageModel;
    public function embeddingModel(string $modelId): EmbeddingModel;
}
```

---

### `LanguageModelMiddleware`

Interface for middleware that can intercept and transform model calls.

```php
namespace BengalStudio\AI\Contracts;

interface LanguageModelMiddleware
{
    public function transformParams(array $params, LanguageModel $model): array;
    public function wrapGenerate(callable $doGenerate, array $params, LanguageModel $model): mixed;
    public function wrapStream(callable $doStream, array $params, LanguageModel $model): mixed;
}
```

---

## Types

### `FinishReason` (enum)

Describes why a model stopped generating.

| Value | String | Description |
|-------|--------|-------------|
| `Stop` | `'stop'` | Natural end of generation |
| `Length` | `'length'` | Token limit reached |
| `ToolCalls` | `'tool-calls'` | Model wants to call tools |
| `ContentFilter` | `'content-filter'` | Content was filtered |
| `Error` | `'error'` | An error occurred |
| `Other` | `'other'` | Other/unknown reason |
| `Unknown` | `'unknown'` | Reason could not be determined |

---

### `Message`

Represents a conversation message.

```php
$message = new Message(
    role: 'user',        // 'system', 'user', 'assistant', 'tool'
    content: 'Hello',    // string or array of content parts
    providerOptions: [], // Optional provider-specific data
);

// Static factory methods
Message::system('You are helpful.');
Message::user('Hello');
Message::user([['type' => 'text', 'text' => 'Describe this'], ['type' => 'image', 'url' => '...']]);
Message::assistant('Hi there!');
Message::tool([['type' => 'tool-result', 'toolCallId' => '...', 'output' => '...']]);
```

---

### `LanguageModelUsage`

Token usage tracking.

```php
$usage = new LanguageModelUsage(
    inputTokens: 100,
    outputTokens: 50,
    totalTokens: null,      // Auto-calculated if null
    reasoningTokens: null,
    cachedInputTokens: null,
);

$usage->total();           // 150
$combined = $usage->add($otherUsage);
$usage->toArray();
```

---

### `LanguageModelCallOptions`

Options passed to `doGenerate()` and `doStream()`.

| Property | Type | Description |
|----------|------|-------------|
| `prompt` | `array` | Prompt messages |
| `maxOutputTokens` | `?int` | Max tokens to generate |
| `temperature` | `?float` | Sampling temperature (0–2) |
| `topP` | `?float` | Nucleus sampling |
| `topK` | `?int` | Top-K sampling |
| `frequencyPenalty` | `?float` | Frequency penalty (-2 to 2) |
| `presencePenalty` | `?float` | Presence penalty (-2 to 2) |
| `stopSequences` | `?array` | Stop sequences |
| `responseFormat` | `?array` | Response format (e.g. JSON schema) |
| `seed` | `?int` | Random seed |
| `tools` | `?array` | Available tools |
| `toolChoice` | `?array` | Tool selection strategy |
| `providerOptions` | `?array` | Provider-specific options |

---

### `LanguageModelGenerateResult`

Returned by `LanguageModel::doGenerate()`.

| Property | Type | Description |
|----------|------|-------------|
| `content` | `array` | Content parts (text, tool-calls) |
| `finishReason` | `string` | Why generation stopped |
| `usage` | `LanguageModelUsage` | Token usage |
| `warnings` | `?array` | Warnings from the provider |
| `response` | `?LanguageModelResponseMetadata` | Response metadata |
| `providerMetadata` | `?array` | Extra provider data |
| `request` | `?array` | Raw request data |

Methods: `getText(): string`, `getToolCalls(): array`

---

### `LanguageModelResponseMetadata`

| Property | Type | Description |
|----------|------|-------------|
| `id` | `?string` | Response ID |
| `timestamp` | `?DateTimeInterface` | Timestamp |
| `modelId` | `?string` | Model that generated |
| `headers` | `?array` | Response headers |
| `body` | `mixed` | Raw response body |

---

### `EmbeddingModelUsage`

```php
$usage = new EmbeddingModelUsage(tokens: 150);
$combined = $usage->add($otherUsage);
$usage->toArray(); // ['tokens' => 150]
```

---

## Exceptions

All exceptions extend `AIException`, which extends `\RuntimeException`.

| Exception | When Thrown |
|-----------|------------|
| `AIException` | Base exception for all AI SDK errors |
| `APICallException` | API request failed (HTTP error). Properties: `statusCode`, `responseBody`, `url` |
| `NoObjectGeneratedException` | `generateObject` failed to produce valid object. Properties: `text`, `reason` |
| `NoSuchModelException` | Model ID not found in provider/registry. Properties: `modelId`, `modelType` |
| `NoSuchProviderException` | Provider not found in registry. Properties: `providerId` |
| `RetryException` | All retry attempts exhausted. Properties: `maxRetries`, `errors` |
| `TooManyEmbeddingValuesException` | More values than model supports per call. Properties: `provider`, `modelId`, `maxEmbeddingsPerCall`, `providedCount` |

---

## Result Objects

### `GenerateTextResult`

Returned by `generateText()`.

| Property / Method | Type | Description |
|-------------------|------|-------------|
| `text` | `string` | The generated text |
| `toolCalls` | `array` | Tool calls from the final step |
| `toolResults` | `array` | Tool execution results |
| `steps` | `StepResult[]` | All intermediate steps |
| `finishReason` | `FinishReason` | Why generation stopped |
| `usage` | `LanguageModelUsage` | Total usage across all steps |
| `response` | `LanguageModelResponseMetadata` | Response metadata |
| `warnings` | `array` | Warnings |
| `toArray()` | `array` | Serialize to array |

---

### `StepResult`

A single step within `GenerateTextResult`.

| Property | Type | Description |
|----------|------|-------------|
| `text` | `string` | Text from this step |
| `toolCalls` | `array` | Tool calls made |
| `toolResults` | `array` | Tool results |
| `finishReason` | `FinishReason` | Step finish reason |
| `usage` | `LanguageModelUsage` | Step token usage |
| `response` | `LanguageModelResponseMetadata` | Step metadata |
| `warnings` | `array` | Warnings |

---

### `GenerateObjectResult`

Returned by `generateObject()`.

| Property / Method | Type | Description |
|-------------------|------|-------------|
| `object` | `array` | The structured object |
| `finishReason` | `FinishReason` | Why generation stopped |
| `usage` | `LanguageModelUsage` | Token usage |
| `response` | `LanguageModelResponseMetadata` | Response metadata |
| `warnings` | `array` | Warnings |
| `get(string $path, $default)` | `mixed` | Get value by dot-notation path |
| `toJson(bool $pretty)` | `string` | Serialize to JSON |
| `toArray()` | `array` | Serialize to array |

---

### `StreamTextResult`

Returned by `streamText()`.

| Method | Description |
|--------|-------------|
| `getTextStream()` | Generator yielding text deltas |
| `getFullStream()` | Generator yielding all event types |
| `pipeTextStreamToResponse()` | Pipe stream to HTTP response (SSE) |

---

### `StreamObjectResult`

Returned by `streamObject()`.

| Method | Description |
|--------|-------------|
| `getPartialObjectStream()` | Generator yielding progressively complete objects |
| `getObject()` | Get the final complete object |

---

### `EmbedResult`

Returned by `embed()`.

| Property / Method | Type | Description |
|-------------------|------|-------------|
| `embedding` | `float[]` | The embedding vector |
| `usage` | `EmbeddingModelUsage` | Token usage |
| `getDimensions()` | `int` | Number of dimensions |

---

### `EmbedManyResult`

Returned by `embedMany()`.

| Property / Method | Type | Description |
|-------------------|------|-------------|
| `embeddings` | `float[][]` | Array of embedding vectors |
| `usage` | `EmbeddingModelUsage` | Total token usage |
