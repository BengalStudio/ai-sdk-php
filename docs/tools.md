# Tools Guide

Tools let a language model call functions you define, enabling agentic workflows where the model can retrieve data, perform calculations, or trigger side-effects.

## Defining a Tool

```php
use function BengalStudio\AI\tool;

$weatherTool = tool([
    'description' => 'Get the current weather in a city',
    'parameters' => [
        'type' => 'object',
        'properties' => [
            'city' => [
                'type' => 'string',
                'description' => 'The city name',
            ],
        ],
        'required' => ['city'],
    ],
    'execute' => function (array $args): string {
        // Return a string or array — arrays are JSON-encoded automatically
        return "72°F and sunny in {$args['city']}";
    },
]);
```

### Tool Options

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `description` | `string` | Yes | Tells the model what the tool does |
| `parameters` | `array` | Yes | JSON Schema defining expected input |
| `execute` | `Closure` | No | Function to run when the tool is called |

> If `execute` is omitted, the tool call is included in the result but not executed automatically. This is useful for client-side execution.

---

## Using Tools with `generateText`

```php
use function BengalStudio\AI\generateText;

$result = generateText([
    'model' => $model,
    'prompt' => 'What is the weather in San Francisco?',
    'tools' => [
        'weather' => $weatherTool,
    ],
    'maxSteps' => 3,
]);

echo $result->text; // Final text after tool execution
```

### Multi-Step Tool Calling

When `maxSteps > 1`, the SDK will:

1. Send the prompt to the model.
2. If the model returns tool calls, execute them.
3. Append the results as tool messages and call the model again.
4. Repeat until the model stops calling tools or `maxSteps` is reached.

Each iteration is recorded as a `StepResult`:

```php
foreach ($result->steps as $step) {
    echo "Step finish reason: {$step->finishReason->value}\n";
    echo "Tool calls: " . count($step->toolCalls) . "\n";
}
```

---

## Tool Choice

Control how the model selects tools:

```php
$result = generateText([
    'model' => $model,
    'prompt' => 'Do a calculation',
    'tools' => ['calc' => $calcTool, 'weather' => $weatherTool],
    'toolChoice' => 'auto',      // Model decides (default)
    // 'toolChoice' => 'none',   // Prevent tool calls
    // 'toolChoice' => 'required', // Force at least one tool call
    // 'toolChoice' => ['type' => 'tool', 'toolName' => 'calc'], // Force specific tool
]);
```

---

## Multiple Tools

```php
$result = generateText([
    'model' => $model,
    'system' => 'You are a helpful assistant with access to tools.',
    'prompt' => 'Find the weather in SF then convert 72°F to Celsius.',
    'tools' => [
        'weather' => tool([
            'description' => 'Get weather for a city',
            'parameters' => [
                'type' => 'object',
                'properties' => ['city' => ['type' => 'string']],
                'required' => ['city'],
            ],
            'execute' => fn(array $args) => '72°F and sunny',
        ]),
        'convert' => tool([
            'description' => 'Convert temperature between units',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'value' => ['type' => 'number'],
                    'from' => ['type' => 'string', 'enum' => ['F', 'C']],
                    'to' => ['type' => 'string', 'enum' => ['F', 'C']],
                ],
                'required' => ['value', 'from', 'to'],
            ],
            'execute' => function (array $args): string {
                if ($args['from'] === 'F' && $args['to'] === 'C') {
                    return (string) round(($args['value'] - 32) * 5 / 9, 1);
                }
                return (string) round($args['value'] * 9 / 5 + 32, 1);
            },
        ]),
    ],
    'maxSteps' => 5,
]);
```

---

## Accessing Tool Results

```php
// From the final result
foreach ($result->toolResults as $toolResult) {
    echo "{$toolResult->toolName}: {$toolResult->result}\n";
}

// From each step
foreach ($result->steps as $step) {
    foreach ($step->toolCalls as $call) {
        echo "Called {$call->toolName} with " . json_encode($call->args) . "\n";
    }
    foreach ($step->toolResults as $tr) {
        echo "Result: {$tr->result}\n";
    }
}
```

---

## Tools Without Execution (Client-Side)

Omit `execute` when you want to handle tool calls on the client:

```php
$search = tool([
    'description' => 'Search the web',
    'parameters' => [
        'type' => 'object',
        'properties' => ['query' => ['type' => 'string']],
        'required' => ['query'],
    ],
    // No 'execute' — tool calls will be returned but not run
]);

$result = generateText([
    'model' => $model,
    'prompt' => 'Find the latest PHP version',
    'tools' => ['search' => $search],
]);

// Handle tool calls yourself
foreach ($result->toolCalls as $call) {
    $searchResults = mySearchFunction($call->args['query']);
    // Continue the conversation with results...
}
```

---

## Tools with Streaming

Tools work the same way with `streamText`:

```php
use function BengalStudio\AI\streamText;

$result = streamText([
    'model' => $model,
    'prompt' => 'What is the weather?',
    'tools' => ['weather' => $weatherTool],
    'maxSteps' => 3,
]);

foreach ($result->getFullStream() as $event) {
    match ($event['type']) {
        'text-delta' => print($event['textDelta']),
        'tool-call' => print("Calling: {$event['toolName']}\n"),
        'tool-result' => print("Result: {$event['result']}\n"),
        default => null,
    };
}
```
