# Architecture

An overview of the `bengal-studio/ai-sdk-php` package architecture, modeled after [Vercel's AI SDK](https://github.com/vercel/ai).

## Design Principles

1. **Provider-agnostic** — Core logic is decoupled from any specific LLM provider via contracts (interfaces).
2. **Specification-driven** — Models implement a small, versioned interface (`specificationVersion: 'v1'`).
3. **Functional API** — Top-level functions (`generateText`, `embed`, etc.) are the primary entry points.
4. **Composable** — Middleware, registries, and custom providers allow flexible configuration.
5. **PHP-idiomatic** — Uses readonly properties, enums, named arguments, and value objects.

## Package Structure

```
src/
├── Contracts/     Interfaces every provider must implement
├── Core/          Orchestration logic (generate, stream, embed)
├── Exceptions/    Exception hierarchy
├── Prompt/        Prompt conversion and call-settings validation
├── Registry/      Provider registry and custom provider
├── Tool/          Tool definition, calling, and result handling
├── Types/         Value objects (Message, Usage, FinishReason, etc.)
├── Util/          Retry, ID generation, cosine similarity
└── functions.php  Convenience function wrappers
```

## Layer Diagram

```
┌──────────────────────────────────────────────┐
│              Application Code                │
│   generateText()  streamText()  embed() ...  │
└──────────────────┬───────────────────────────┘
                   │
┌──────────────────▼───────────────────────────┐
│               Core Layer                     │
│   GenerateText  StreamText  GenerateObject   │
│   StreamObject  Embed  EmbedMany             │
│   ┌──────────┐  ┌───────────┐  ┌──────────┐ │
│   │PromptConv│  │ToolPrepare│  │  Retry    │ │
│   └──────────┘  └───────────┘  └──────────┘ │
└──────────────────┬───────────────────────────┘
                   │
┌──────────────────▼───────────────────────────┐
│            Contracts Layer                   │
│   LanguageModel    EmbeddingModel            │
│   Provider         LanguageModelMiddleware    │
└──────────────────┬───────────────────────────┘
                   │
┌──────────────────▼───────────────────────────┐
│         Provider Implementations             │
│   ai-sdk-php/openai   (future: anthropic…)   │
└──────────────────────────────────────────────┘
```

## Key Flows

### Text Generation (`generateText`)

1. **Input validation** — `CallSettings::validate()` checks temperature bounds, maxOutputTokens, etc.
2. **Prompt conversion** — `PromptConverter::toLanguageModelPrompt()` normalises string/messages into a `Message[]` array.
3. **Tool preparation** — `ToolPreparer::prepare()` converts `Tool` objects for the model.
4. **Step loop** — Iterates up to `maxSteps`:
   a. Calls `model->doGenerate()` (with retry wrapper).
   b. If the model requests tool calls and an `execute` function exists, runs them.
   c. Appends assistant + tool messages to the conversation.
   d. Fires `onStepFinish` callback.
5. **Result assembly** — Builds `GenerateTextResult` from all `StepResult` instances.

### Streaming (`streamText`)

Same preparation as `generateText`, but calls `model->doStream()`. The returned `StreamTextResult` exposes:

- `getTextStream()` — generator yielding only text deltas.
- `getFullStream()` — generator yielding all event types (text-delta, tool-call, tool-result, finish).
- `pipeTextStreamToResponse()` — writes Server-Sent Events directly to the HTTP response.

### Structured Output (`generateObject`)

1. Depending on `mode`:
   - **`json`** — passes the schema as `responseFormat` and extracts JSON from text.
   - **`tool`** — wraps the schema as a tool, forces `toolChoice`, and extracts from tool call args.
2. Parses the JSON output and validates it.
3. Retries on parse failures up to `maxRetries`.

### Embeddings (`embed` / `embedMany`)

1. `embed()` wraps a single value in an array and calls the model.
2. `embedMany()` splits values into chunks of `maxEmbeddingsPerCall()` and calls the model for each chunk, combining results.

## Middleware

`LanguageModelMiddleware` can intercept calls at three points:

```php
class LoggingMiddleware implements LanguageModelMiddleware
{
    public function transformParams(array $params, LanguageModel $model): array
    {
        // Modify params before they reach the model
        return $params;
    }

    public function wrapGenerate(callable $doGenerate, array $params, LanguageModel $model): mixed
    {
        // Wrap the generate call (e.g. logging, caching)
        $result = $doGenerate($params);
        return $result;
    }

    public function wrapStream(callable $doStream, array $params, LanguageModel $model): mixed
    {
        // Wrap the stream call
        return $doStream($params);
    }
}
```

## Provider Registry

The `ProviderRegistry` resolves models by string ID using a configurable separator (default `:`):

```
"openai:gpt-4o"  →  $providers['openai']->languageModel('gpt-4o')
```

`CustomProvider` allows aliasing models:

```
"fast"  →  $languageModels['fast']  →  actual model instance
```

If a name isn't found, it falls back to an optional `fallbackProvider`.

## Error Handling & Retry

- All transient API errors are retried via `Util\Retry` with exponential backoff.
- The maximum retry count is configurable per call (default: 2, meaning up to 3 total attempts).
- Permanent errors (validation, auth) throw immediately.
- `RetryException` wraps all collected errors when retries are exhausted.

## Extending the SDK

See [providers.md](providers.md) for a guide on implementing a new provider.
