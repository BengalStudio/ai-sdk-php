# Implementing a Provider

This guide explains how to create a new LLM provider for the AI SDK PHP core package.

## Overview

A provider is a package that implements the `LanguageModel` and/or `EmbeddingModel` contracts, translating between the AI SDK's unified interface and a specific API (e.g. Anthropic, Google Gemini, Mistral).

## Step 1 — Implement `LanguageModel`

```php
<?php

namespace MyVendor\MyProvider;

use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Types\LanguageModelCallOptions;
use BengalStudio\AI\Types\LanguageModelGenerateResult;
use BengalStudio\AI\Types\LanguageModelStreamResult;
use BengalStudio\AI\Types\LanguageModelUsage;
use BengalStudio\AI\Types\LanguageModelResponseMetadata;

class MyLanguageModel implements LanguageModel
{
    public function __construct(
        private readonly string $modelId,
        private readonly string $apiKey,
    ) {}

    public function specificationVersion(): string
    {
        return 'v1';
    }

    public function provider(): string
    {
        return 'my-provider';
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function doGenerate(LanguageModelCallOptions $options): LanguageModelGenerateResult
    {
        // 1. Convert $options->prompt (Message[]) to your API format
        // 2. Include $options->tools if your API supports function calling
        // 3. Apply $options->temperature, $options->maxOutputTokens, etc.
        // 4. Make the HTTP request
        // 5. Map the response to LanguageModelGenerateResult

        $response = $this->callApi($options);

        return new LanguageModelGenerateResult(
            content: [
                ['type' => 'text', 'text' => $response['text']],
                // Include tool calls if the model requested any:
                // ['type' => 'tool-call', 'toolCallId' => '...', 'toolName' => '...', 'args' => [...]],
            ],
            finishReason: 'stop', // 'stop', 'length', 'tool-calls', 'content-filter', 'error', 'other'
            usage: new LanguageModelUsage(
                inputTokens: $response['usage']['input'],
                outputTokens: $response['usage']['output'],
            ),
            response: new LanguageModelResponseMetadata(
                id: $response['id'],
                modelId: $this->modelId,
            ),
            warnings: [],
        );
    }

    public function doStream(LanguageModelCallOptions $options): LanguageModelStreamResult
    {
        $generator = function () use ($options): \Generator {
            // Stream from your API and yield events:

            foreach ($this->streamApi($options) as $chunk) {
                // Text deltas
                yield [
                    'type' => 'text-delta',
                    'textDelta' => $chunk['delta'],
                ];
            }

            // Final event (required)
            yield [
                'type' => 'finish',
                'finishReason' => 'stop',
                'usage' => new LanguageModelUsage(
                    inputTokens: 100,
                    outputTokens: 50,
                ),
            ];
        };

        return new LanguageModelStreamResult($generator());
    }
}
```

### Content Parts

The `content` array in `LanguageModelGenerateResult` supports these part types:

| Type | Required Fields | Description |
|------|----------------|-------------|
| `text` | `text` | Generated text content |
| `tool-call` | `toolCallId`, `toolName`, `args` | Model requesting a tool call |

### Finish Reasons

Map your API's stop reason to one of these strings:

| Value | When to Use |
|-------|-------------|
| `stop` | Normal completion |
| `length` | Token limit reached |
| `tool-calls` | Model wants to call tools |
| `content-filter` | Content was filtered/blocked |
| `error` | An error occurred during generation |
| `other` | Anything else / unknown |

### Stream Events

The generator passed to `LanguageModelStreamResult` should yield these events:

| Type | Fields | Description |
|------|--------|-------------|
| `text-delta` | `textDelta: string` | Incremental text |
| `tool-call-delta` | `toolCallId`, `toolName`, `argsTextDelta` | Incremental tool call |
| `tool-call` | `toolCallId`, `toolName`, `args` | Complete tool call |
| `finish` | `finishReason`, `usage` | **Required** — end of stream |

---

## Step 2 — Implement `EmbeddingModel` (Optional)

```php
<?php

namespace MyVendor\MyProvider;

use BengalStudio\AI\Contracts\EmbeddingModel;
use BengalStudio\AI\Types\EmbeddingModelCallOptions;
use BengalStudio\AI\Types\EmbeddingModelResult;
use BengalStudio\AI\Types\EmbeddingModelUsage;

class MyEmbeddingModel implements EmbeddingModel
{
    public function specificationVersion(): string
    {
        return 'v1';
    }

    public function provider(): string
    {
        return 'my-provider';
    }

    public function modelId(): string
    {
        return 'my-embedding-model';
    }

    public function maxEmbeddingsPerCall(): ?int
    {
        return 2048; // null = no limit
    }

    public function supportsParallelCalls(): bool
    {
        return true;
    }

    public function doEmbed(EmbeddingModelCallOptions $options): EmbeddingModelResult
    {
        // $options->values contains the strings to embed
        $response = $this->callEmbeddingApi($options->values);

        return new EmbeddingModelResult(
            embeddings: $response['embeddings'], // array<array<float>>
            usage: new EmbeddingModelUsage(tokens: $response['total_tokens']),
        );
    }
}
```

---

## Step 3 — Implement the `Provider` Factory

```php
<?php

namespace MyVendor\MyProvider;

use BengalStudio\AI\Contracts\Provider;
use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Contracts\EmbeddingModel;

class MyProvider implements Provider
{
    public function __construct(
        private readonly string $apiKey,
    ) {}

    public function languageModel(string $modelId): LanguageModel
    {
        return new MyLanguageModel($modelId, $this->apiKey);
    }

    public function embeddingModel(string $modelId): EmbeddingModel
    {
        return new MyEmbeddingModel($modelId, $this->apiKey);
    }
}
```

---

## Step 4 — Add Convenience Functions

```php
<?php

namespace MyVendor\MyProvider;

function createMyProvider(array $settings = []): MyProvider
{
    return new MyProvider(
        apiKey: $settings['apiKey'] ?? getenv('MY_PROVIDER_API_KEY') ?: '',
    );
}
```

---

## Step 5 — Handle Provider Options

Provider-specific options are passed via `$options->providerOptions['my-provider']`. Use these for features unique to your API:

```php
$providerOpts = $options->providerOptions['my-provider'] ?? [];
$customParam = $providerOpts['myCustomParam'] ?? null;
```

If an option isn't supported, add a warning instead of throwing:

```php
$warnings = [];
if (isset($options->topK)) {
    $warnings[] = ['type' => 'unsupported', 'feature' => 'topK'];
}
```

---

## Step 6 — Error Handling

- Throw `APICallException` for HTTP-level errors:

```php
use BengalStudio\AI\Exceptions\APICallException;

throw new APICallException(
    message: 'Rate limit exceeded',
    statusCode: 429,
    responseBody: $rawBody,
    url: $requestUrl,
);
```

- The core's retry mechanism will automatically retry if `statusCode` is retryable (408, 429, 5xx).

---

## Composer Package Setup

```json
{
    "name": "my-vendor/ai-sdk-my-provider",
    "require": {
        "php": "^8.1",
        "bengal-studio/ai-sdk-php": "^1.0",
        "guzzlehttp/guzzle": "^7.0"
    },
    "autoload": {
        "psr-4": {
            "MyVendor\\MyProvider\\": "src/"
        },
        "files": ["src/functions.php"]
    }
}
```

---

## Testing Your Provider

Use PHPUnit to test your message conversion, error handling, and utility methods without making real API calls. Test the public contracts to ensure compatibility:

```php
public function testImplementsLanguageModel(): void
{
    $model = new MyLanguageModel('test-model', 'key');
    $this->assertInstanceOf(\BengalStudio\AI\Contracts\LanguageModel::class, $model);
    $this->assertSame('v1', $model->specificationVersion());
    $this->assertSame('my-provider', $model->provider());
    $this->assertSame('test-model', $model->modelId());
}
```

See the [ai-sdk-php/openai](https://github.com/bengal-studio/ai-sdk-php-openai) package for a complete reference implementation.
