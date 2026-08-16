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
| `needsApproval` | `bool\|Closure` | No | Hold the call until a human decides. See [Tool Approvals](#tool-approvals) |

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

---

## Tool Approvals

Some tools should not run unsupervised. `needsApproval` holds the call, announces
it, and ends the run — the tool does **not** execute until a human decides.

```php
$deletePost = tool([
    'description' => 'Delete a post',
    'parameters' => [
        'type' => 'object',
        'properties' => ['id' => ['type' => 'integer']],
        'required' => ['id'],
    ],
    'execute' => fn(array $args) => wp_delete_post($args['id']),
    'needsApproval' => true,
]);
```

A closure decides per call, and sees the input:

```php
'needsApproval' => fn(array $input, array $options) => $input['id'] !== 1,
```

### Supplying your own approval id

Returning a **string** from the closure uses that string as the approval id
instead of a generated one. This is an extension of the TypeScript SDK, and it
exists so you can bind an approval to a record you already own — a queue row, an
audit entry — rather than keeping a side table mapping one id to the other:

```php
'needsApproval' => function (array $input) use ($queue): bool|string {
    if (! $queue->isGated($input)) {
        return false;
    }
    return $queue->record($input);   // returns the row key
},
```

### The flow

1. The model calls the tool. `needsApproval` says yes, so the call is **not**
   executed and the run ends with a `tool-approval-request` event carrying the
   approval id and the tool call id.
2. Your UI shows the request. With `@ai-sdk/react`, the tool part arrives in
   `state: 'approval-requested'`; respond with `addToolApprovalResponse()` and
   set `sendAutomaticallyWhen: lastAssistantMessageIsCompleteWithApprovalResponses`
   so the decision is sent back.
3. On the next request `convertToModelMessages()` replays the decision, and
   `streamText()` settles it before calling the model: approved calls execute,
   denied ones resolve with a refusal the model can read.

Approved calls receive the decision in their execute options, so you can redeem
whatever you recorded in step 1:

```php
'execute' => function (array $input, array $options = []) use ($queue) {
    if (isset($options['approval'])) {
        $queue->redeem($options['approval']['id']);   // single-use
    }
    return wp_delete_post($input['id']);
},
```

### What the model sees

A call held for approval is never fed back to the model as a failure, and the
run stops rather than taking another step — so the model does not narrate a
decision nobody has made. Denials come back as an ordinary tool result saying
the user refused, which is enough for the model to move on.

An approval that nobody has decided is dropped from replayed history entirely:
it has no result and never will unless a human acts, and an assistant tool call
with no matching tool result is rejected outright by some providers.
