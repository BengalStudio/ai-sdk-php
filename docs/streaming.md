# Streaming Guide

Stream text and structured output in real-time from language models.

## Streaming Text

```php
use function BengalStudio\AI\streamText;

$result = streamText([
    'model' => $model,
    'prompt' => 'Write a poem about PHP.',
]);
```

### Text Stream

Yields only text deltas — ideal for simple display:

```php
foreach ($result->getTextStream() as $delta) {
    echo $delta;
    flush();
}
```

### Full Stream

Yields all events including tool calls:

```php
foreach ($result->getFullStream() as $event) {
    match ($event['type']) {
        'text-delta'  => print($event['textDelta']),
        'tool-call'   => handleToolCall($event),
        'tool-result' => handleToolResult($event),
        'finish'      => handleFinish($event),
        default       => null,
    };
}
```

### Server-Sent Events (SSE)

Pipe the stream directly to an HTTP response:

```php
$result = streamText([
    'model' => $model,
    'prompt' => 'Hello!',
]);

// Send as SSE to a browser client
$result->pipeTextStreamToResponse(format: 'sse');
```

This sets the correct `Content-Type: text/event-stream` headers and writes each delta as an SSE event.

## Streaming Objects

```php
use function BengalStudio\AI\streamObject;

$result = streamObject([
    'model' => $model,
    'schema' => [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'body' => ['type' => 'string'],
        ],
    ],
    'prompt' => 'Write a blog post.',
]);

// Partial objects become progressively more complete
foreach ($result->getPartialObjectStream() as $partial) {
    // {"title":"My..."}
    // {"title":"My Blog","body":"Today..."}
    echo json_encode($partial) . "\n";
}

// Or get the final complete object
$complete = $result->getObject();
```

## Streaming with Tools

Tools work seamlessly with streaming. Set `maxSteps > 1` for multi-step tool use:

```php
$result = streamText([
    'model' => $model,
    'prompt' => 'What is the weather?',
    'tools' => ['weather' => $weatherTool],
    'maxSteps' => 3,
]);

foreach ($result->getFullStream() as $event) {
    match ($event['type']) {
        'text-delta' => print($event['textDelta']),
        'tool-call' => print("\n[Calling {$event['toolName']}...]\n"),
        'tool-result' => print("[Result: {$event['result']}]\n"),
        default => null,
    };
}
```

## Smoothing streamed output

Provider deltas often arrive in irregular chunks — sometimes a full sentence at
once, sometimes a single character — which looks jittery in the UI. The
`smoothStream` transform buffers incoming `text-delta` (and `reasoning`) chunks
and re-emits them in evenly-paced pieces (word, line, regex, callable, or
locale-aware), with an optional inter-chunk delay.

```php
use function BengalStudio\AI\{smoothStream, streamText};

$result = streamText([
    'model'                  => $model,
    'prompt'                 => 'Write a poem about PHP.',
    'experimental_transform' => smoothStream([
        'delayInMs' => 20,
        'chunking'  => 'word',
    ]),
]);

foreach ($result->getTextStream() as $delta) {
    echo $delta;
    flush();
}
```

Other chunk types (tool calls, tool results, step-finish, finish) pass through
unchanged. The buffer is always flushed before a non-smoothable chunk and at
end of stream, so no partial text is ever dropped.

### Options

| Key | Type | Default | Description |
|---|---|---|---|
| `delayInMs` | `int\|null` | `10` | Sleep between emitted chunks. `null` or `0` disables the sleep. |
| `chunking` | `'word'\|'line'\|string\|callable\|\IntlBreakIterator` | `'word'` | How to split buffered text. |

`chunking` accepts:

- **`'word'`** — splits at whitespace boundaries (`/\S+\s+/m`).
- **`'line'`** — splits at one-or-more newlines (`/\n+/m`).
- **A PCRE pattern string** — e.g. `'/[^_]*_/'` to chunk on underscore-terminated segments.
- **A callable** `fn(string $buffer): ?string` that returns the next prefix to emit, or `null` if not ready. Must return a non-empty prefix of the buffer.
- **An `\IntlBreakIterator`** (requires `ext-intl`) — recommended for CJK languages where whitespace boundaries are unreliable.

### Custom chunking

```php
$result = streamText([
    'model'  => $model,
    'prompt' => '...',
    'experimental_transform' => smoothStream([
        'chunking' => fn(string $buf) =>
            preg_match('/.+?[\.\?!]\s/', $buf, $m) ? $m[0] : null,
    ]),
]);
```

### Locale-aware (CJK)

```php
$result = streamText([
    'model'  => $model,
    'prompt' => '日本語で書いてください。',
    'experimental_transform' => smoothStream([
        'chunking' => \IntlBreakIterator::createWordInstance('ja'),
    ]),
]);
```

## Streaming Options

`streamText` accepts all the same options as `generateText`:

| Key | Type | Description |
|-----|------|-------------|
| `model` | `LanguageModel` | Required — the language model |
| `prompt` / `messages` | `string` / `Message[]` | The input |
| `system` | `string` | System message |
| `tools` | `array<string, Tool>` | Available tools |
| `maxSteps` | `int` | Max tool-call steps |
| `temperature` | `float` | Sampling temperature |
| `maxOutputTokens` | `int` | Token limit |
| `onChunk` | `callable` | Called for each chunk |
| `onFinish` | `callable` | Called when done |
| `experimental_transform` | `\Closure\|array` | Stream transform(s) — see [`smoothStream`](#smoothing-streamed-output) |

`toUIMessageStreamResponse()` additionally accepts `messageId`. Pass the id the
client sent when a run continues an existing assistant message — a run resumed
after a [tool approval](tools.md#tool-approvals) does exactly that — otherwise a
fresh id is generated and the client renames the message it was building.

## Stream Events Reference

### `text-delta`

```php
['type' => 'text-delta', 'textDelta' => 'Hello']
```

### `tool-call-delta`

```php
['type' => 'tool-call-delta', 'toolCallId' => '...', 'toolName' => '...', 'argsTextDelta' => '{"ci']
```

### `tool-call`

```php
['type' => 'tool-call', 'toolCallId' => '...', 'toolName' => 'weather', 'args' => ['city' => 'SF']]
```

### `tool-result`

```php
['type' => 'tool-result', 'toolCallId' => '...', 'toolName' => 'weather', 'result' => '72°F']
```

### `tool-approval-request`

Emitted **instead of** `tool-result` when a tool's `needsApproval` holds the
call. The tool has not run, and the run ends here. See
[Tool Approvals](tools.md#tool-approvals).

```php
['type' => 'tool-approval-request', 'approvalId' => '...', 'toolCallId' => '...']
```

### `tool-output-denied`

Emitted on resume when a human refused the call.

```php
['type' => 'tool-output-denied', 'toolCallId' => '...', 'reason' => 'too risky']
```

> The `reason` is dropped from the UI-message-stream chunk — the AI SDK v6
> schema for `tool-output-denied` is a strict object of `{type, toolCallId}`,
> and any extra key fails the client's parse. The reason still reaches the model
> in the tool result.

### `tool-output-error`

```php
['type' => 'tool-output-error', 'toolCallId' => '...', 'errorText' => '...']
```

### `finish`

```php
['type' => 'finish', 'finishReason' => 'stop', 'usage' => LanguageModelUsage(...)]
```
