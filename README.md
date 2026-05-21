# AI SDK for PHP

A PHP port of [Vercel's AI SDK](https://github.com/vercel/ai) — a unified interface for interacting with large language models (LLMs), embedding models, and AI tools.

## Requirements

- PHP 8.1+
- Composer

## Installation

```bash
composer require bengal-studio/ai-sdk
```

## Quick Start

```php
use function BengalStudio\AI\generateText;

$result = generateText([
    'model' => $yourLanguageModel,
    'prompt' => 'What is love?',
]);

echo $result->text;
```

## Core Functions

### `generateText`

Generate text for a given prompt using a language model. Supports multi-step tool calling.

```php
use function BengalStudio\AI\generateText;
use function BengalStudio\AI\tool;

$result = generateText([
    'model'    => $model,
    'system'   => 'You are a helpful assistant.',
    'prompt'   => 'What is the weather in San Francisco?',
    'tools'    => [
        'weather' => tool([
            'description' => 'Get the weather in a location',
            'parameters'  => [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'The city and state'],
                ],
                'required' => ['location'],
            ],
            'execute' => function (array $args): string {
                return "72°F and sunny in {$args['location']}";
            },
        ]),
    ],
    'maxSteps' => 5,
]);

echo $result->text;
echo $result->usage->total(); // Total tokens used
```

### `streamText`

Stream text output from a language model. Ideal for real-time UI updates.

```php
use function BengalStudio\AI\streamText;

$result = streamText([
    'model'  => $model,
    'prompt' => 'Write a poem about PHP.',
]);

// Stream text deltas
foreach ($result->getTextStream() as $delta) {
    echo $delta;
    flush();
}

// Or pipe directly to HTTP response (SSE)
$result->pipeTextStreamToResponse(format: 'sse');
```

#### Streaming with `@ai-sdk/react`

The SDK supports both stream protocols used by the Vercel AI SDK React hooks.

**Data Stream Protocol** (default for `useChat`):

```php
use function BengalStudio\AI\streamText;
use function BengalStudio\AI\convertToModelMessages;

// In your API endpoint (e.g. WordPress REST API):
$input = $request->get_json_params();
$uiMessages = $input['messages'] ?? [];

$result = streamText([
    'model'    => $model,
    'messages' => convertToModelMessages($uiMessages),
]);

// Returns a Data Stream Protocol response compatible with useChat():
$result->toUIMessageStreamResponse();
exit; // Required in WordPress/framework contexts
```

Frontend (React):
```jsx
import { useChat } from '@ai-sdk/react';

// Data stream is the default — no special transport needed:
const { messages, sendMessage } = useChat();
```

**Text Stream Protocol** (for `TextStreamChatTransport`):

```php
$result = streamText([
    'model'  => $model,
    'prompt' => 'Hello!',
]);

$result->toTextStreamResponse();
exit;
```

Frontend (React):
```jsx
import { useChat } from '@ai-sdk/react';
import { TextStreamChatTransport } from 'ai';

const { messages, sendMessage } = useChat({
    transport: new TextStreamChatTransport({ api: '/api/chat' }),
});
```

**`toUIMessageStreamResponse` options:**

```php
$result->toUIMessageStreamResponse([
    // Attach metadata to events (e.g. usage info)
    'messageMetadata' => function (array $part) {
        if ($part['type'] === 'finish') {
            return ['totalTokens' => $part['totalUsage']['totalTokens'] ?? 0];
        }
        return null;
    },
    // Forward reasoning tokens from models like deepseek-r1
    'sendReasoning' => true,
    // Forward source references
    'sendSources' => true,
    // Custom error message handler
    'onError' => function (\Throwable $e) {
        return 'Something went wrong.';
    },
]);
```

**Tool Streaming with the Data Stream Protocol:**

When tools are defined with `maxSteps > 1`, the stream automatically handles multi-step tool calling. Tool input is streamed incrementally and tool output appears within the same step:

```
start → start-step
      → tool-input-start
      → tool-input-delta (repeated)
      → tool-input-available
      → tool-output-available
      → finish-step
      → start-step
      → text-start
      → text-delta (repeated)
      → text-end
      → finish-step
      → finish
      → [DONE]
```

Example with tools:

```php
$result = streamText([
    'model'    => $model,
    'messages' => convertToModelMessages($messages),
    'tools'    => [
        'weather' => tool([
            'description' => 'Get current weather for a city',
            'parameters'  => [
                'type' => 'object',
                'properties' => [
                    'city' => ['type' => 'string', 'description' => 'City name'],
                ],
                'required' => ['city'],
            ],
            'execute' => fn(array $args) => "72°F and sunny in {$args['city']}",
        ]),
    ],
    'maxSteps' => 3,
]);

$result->toUIMessageStreamResponse();
exit;
```

### `generateObject`

Generate structured data conforming to a JSON Schema.

```php
use function BengalStudio\AI\generateObject;

$result = generateObject([
    'model'  => $model,
    'schema' => [
        'type' => 'object',
        'properties' => [
            'recipe' => [
                'type' => 'object',
                'properties' => [
                    'name'        => ['type' => 'string'],
                    'ingredients' => [
                        'type'  => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name'   => ['type' => 'string'],
                                'amount' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'steps' => [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'prompt' => 'Generate a lasagna recipe.',
]);

$recipe = $result->object['recipe'];
echo $recipe['name'];
```

### `streamObject`

Stream structured data as it's being generated, receiving partial objects.

```php
use function BengalStudio\AI\streamObject;

$result = streamObject([
    'model'  => $model,
    'schema' => [
        'type' => 'object',
        'properties' => [
            'title'   => ['type' => 'string'],
            'content' => ['type' => 'string'],
        ],
    ],
    'prompt' => 'Write a blog post about AI.',
]);

foreach ($result->getPartialObjectStream() as $partial) {
    // $partial is progressively more complete
    echo json_encode($partial) . "\n";
}

// Or get the final object
$object = $result->getObject();
```

### `embed`

Embed a single value using an embedding model.

```php
use function BengalStudio\AI\embed;

$result = embed([
    'model' => $embeddingModel,
    'value' => 'The quick brown fox jumps over the lazy dog.',
]);

$vector = $result->embedding; // float[]
echo "Dimensions: " . $result->getDimensions();
```

### `embedMany`

Embed multiple values. Automatically handles chunking based on the model's limits.

```php
use function BengalStudio\AI\embedMany;
use function BengalStudio\AI\cosineSimilarity;

$result = embedMany([
    'model'  => $embeddingModel,
    'values' => [
        'The cat sat on the mat.',
        'The dog chased the ball.',
        'PHP is a programming language.',
    ],
]);

// Compare similarity between first two embeddings
$similarity = cosineSimilarity(
    $result->embeddings[0],
    $result->embeddings[1],
);
echo "Similarity: $similarity";
```

## Converting UI Messages

When using `@ai-sdk/react`'s `useChat` hook, the frontend sends messages in `UIMessage` format. Use `convertToModelMessages()` to convert them to model messages:

```php
use function BengalStudio\AI\convertToModelMessages;
use function BengalStudio\AI\streamText;

// The frontend sends messages as JSON:
// [
//   { "id": "msg_1", "role": "user", "parts": [{ "type": "text", "text": "Hello" }] },
//   { "id": "msg_2", "role": "assistant", "parts": [{ "type": "text", "text": "Hi!" }] },
//   { "id": "msg_3", "role": "user", "parts": [{ "type": "text", "text": "What's AI?" }] }
// ]

$input = $request->get_json_params(); // or json_decode(file_get_contents('php://input'), true)
$uiMessages = $input['messages'] ?? [];

$result = streamText([
    'model'    => $model,
    'system'   => 'You are a helpful assistant.',
    'messages' => convertToModelMessages($uiMessages),
]);

$result->toUIMessageStreamResponse();
exit;
```

The converter handles:
- **Text parts** → string content for user/assistant messages
- **Tool invocations** → tool-call content parts for assistant messages + tool-result messages
- **File/image parts** → multi-modal content parts for user messages
- **System messages** → system messages

## Tools

Define tools that the model can call during text generation:

```php
use function BengalStudio\AI\tool;

$calculator = tool([
    'description' => 'Perform arithmetic calculations',
    'parameters'  => [
        'type' => 'object',
        'properties' => [
            'expression' => [
                'type'        => 'string',
                'description' => 'The math expression to evaluate',
            ],
        ],
        'required' => ['expression'],
    ],
    'execute' => function (array $args): string {
        // Evaluate the expression (use a proper math parser in production)
        return (string) eval("return {$args['expression']};");
    },
]);
```

## Provider Registry

Register multiple providers and resolve models using string IDs:

```php
use function BengalStudio\AI\createProviderRegistry;
use function BengalStudio\AI\generateText;

$registry = createProviderRegistry([
    'openai'    => $openaiProvider,
    'anthropic' => $anthropicProvider,
]);

// Use 'provider:model' format
$model = $registry->languageModel('openai:gpt-4o');

$result = generateText([
    'model'  => $model,
    'prompt' => 'Hello!',
]);
```

## Custom Provider

Create a provider with explicit model mappings:

```php
use function BengalStudio\AI\customProvider;

$myProvider = customProvider([
    'languageModels' => [
        'fast'   => $gpt4oMini,
        'smart'  => $gpt4o,
        'genius' => $o1,
    ],
    'embeddingModels' => [
        'default' => $textEmbedding3Small,
    ],
]);

$model = $myProvider->languageModel('smart');
```

## Implementing a Provider

To create a provider for a new LLM service, implement the `LanguageModel` interface:

```php
use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Types\LanguageModelCallOptions;
use BengalStudio\AI\Types\LanguageModelGenerateResult;
use BengalStudio\AI\Types\LanguageModelStreamResult;
use BengalStudio\AI\Types\LanguageModelUsage;
use BengalStudio\AI\Types\FinishReason;
use BengalStudio\AI\Types\LanguageModelResponseMetadata;

class MyModel implements LanguageModel
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
        return 'my-model-id';
    }

    public function doGenerate(LanguageModelCallOptions $options): LanguageModelGenerateResult
    {
        // Call your API here
        $response = $this->callApi($options);

        return new LanguageModelGenerateResult(
            content: [
                ['type' => 'text', 'text' => $response['text']],
            ],
            finishReason: FinishReason::Stop,
            usage: new LanguageModelUsage(
                promptTokens: $response['usage']['prompt_tokens'],
                completionTokens: $response['usage']['completion_tokens'],
            ),
            response: new LanguageModelResponseMetadata(
                id: $response['id'],
                modelId: $this->modelId(),
            ),
        );
    }

    public function doStream(LanguageModelCallOptions $options): LanguageModelStreamResult
    {
        // Return a streaming result using a Generator
        $generator = function () use ($options) {
            // Stream from your API
            foreach ($this->streamApi($options) as $chunk) {
                yield [
                    'type'      => 'text-delta',
                    'textDelta' => $chunk['delta'],
                ];
            }

            yield [
                'type'         => 'finish',
                'finishReason' => FinishReason::Stop,
                'usage'        => new LanguageModelUsage(10, 20),
            ];
        };

        return new LanguageModelStreamResult($generator());
    }
}
```

## Architecture

This package mirrors the architecture of the Vercel AI SDK:

```
src/
├── Contracts/           # Interfaces (LanguageModel, EmbeddingModel, Provider, Middleware)
├── Core/                # Core functions (GenerateText, StreamText, GenerateObject, etc.)
├── Exceptions/          # Exception classes
├── Prompt/              # Prompt conversion and call settings
├── Registry/            # Provider registry and custom provider
├── Tool/                # Tool definition and execution
├── Types/               # Value objects (Message, Usage, FinishReason, etc.)
├── Util/                # Utilities (Retry, IdGenerator)
└── functions.php        # Convenience function wrappers
```

## API Mapping

| Vercel AI SDK (TypeScript)                        | AI SDK PHP                                          |
|---------------------------------------------------|-----------------------------------------------------|
| `generateText()`                                  | `BengalStudio\AI\generateText()`                   |
| `streamText()`                                    | `BengalStudio\AI\streamText()`                     |
| `generateObject()`                                | `BengalStudio\AI\generateObject()`                 |
| `streamObject()`                                  | `BengalStudio\AI\streamObject()`                   |
| `embed()`                                         | `BengalStudio\AI\embed()`                          |
| `embedMany()`                                     | `BengalStudio\AI\embedMany()`                      |
| `tool()`                                          | `BengalStudio\AI\tool()`                           |
| `convertToModelMessages()`                        | `BengalStudio\AI\convertToModelMessages()`         |
| `createProviderRegistry()`                        | `BengalStudio\AI\createProviderRegistry()`         |
| `customProvider()`                                | `BengalStudio\AI\customProvider()`                 |
| `cosineSimilarity()`                              | `BengalStudio\AI\cosineSimilarity()`               |
| `result.toUIMessageStreamResponse()`              | `$result->toUIMessageStreamResponse()`             |
| `result.toTextStreamResponse()`                   | `$result->toTextStreamResponse()`                  |
| `result.pipeTextStreamToResponse()` (Node)        | `$result->pipeTextStreamToResponse()`              |

## License

MIT
