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

### `finish`

```php
['type' => 'finish', 'finishReason' => 'stop', 'usage' => LanguageModelUsage(...)]
```
