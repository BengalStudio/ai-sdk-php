# Structured Output (Objects)

Generate and stream structured data that conforms to a JSON Schema.

## Generating Objects

```php
use function BengalStudio\AI\generateObject;

$result = generateObject([
    'model' => $model,
    'schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
            'hobbies' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ],
        ],
        'required' => ['name', 'age'],
    ],
    'prompt' => 'Generate a profile for a fictional person.',
]);

$person = $result->object;
echo $person['name'];     // "Alice"
echo $person['age'];      // 28
```

## Generation Modes

### JSON Mode (Default)

Instructs the model to output JSON matching the schema. The SDK parses and validates the result.

```php
$result = generateObject([
    'model' => $model,
    'mode' => 'json',  // default
    'schema' => $schema,
    'prompt' => '...',
]);
```

### Tool Mode

Wraps the schema as a tool definition, forcing the model to "call" it with structured arguments.

```php
$result = generateObject([
    'model' => $model,
    'mode' => 'tool',
    'schema' => $schema,
    'schemaName' => 'generate_person',
    'schemaDescription' => 'Generate a person profile',
    'prompt' => '...',
]);
```

## Accessing Results

```php
// The parsed object
$obj = $result->object;

// Dot-notation access
$name = $result->get('name');
$firstHobby = $result->get('hobbies.0', 'none');

// Serialize
echo $result->toJson();                // Compact JSON
echo $result->toJson(pretty: true);    // Pretty-printed JSON
echo json_encode($result->toArray());  // Full result as array
```

## Streaming Objects

Stream partial objects as they are generated:

```php
use function BengalStudio\AI\streamObject;

$result = streamObject([
    'model' => $model,
    'schema' => [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'sections' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'heading' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'prompt' => 'Write a blog post about AI in PHP.',
]);

// Stream partial objects (progressively more complete)
foreach ($result->getPartialObjectStream() as $partial) {
    echo json_encode($partial) . "\n";
    // {"title":"AI in..."}
    // {"title":"AI in PHP","sections":[{"heading":"Introduction"}]}
    // {"title":"AI in PHP","sections":[{"heading":"Introduction","content":"..."}]}
}

// Or wait for the final object
$final = $result->getObject();
```

## Complex Schemas

### Nested Objects

```php
$result = generateObject([
    'model' => $model,
    'schema' => [
        'type' => 'object',
        'properties' => [
            'company' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'founded' => ['type' => 'integer'],
                    'ceo' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'age' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'prompt' => 'Generate a tech company profile.',
]);

echo $result->get('company.ceo.name');
```

### Enums and Constraints

```php
$schema = [
    'type' => 'object',
    'properties' => [
        'sentiment' => [
            'type' => 'string',
            'enum' => ['positive', 'negative', 'neutral'],
        ],
        'confidence' => [
            'type' => 'number',
            'minimum' => 0,
            'maximum' => 1,
        ],
        'keywords' => [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'maxItems' => 5,
        ],
    ],
    'required' => ['sentiment', 'confidence'],
];
```

## Error Handling

If the model produces invalid JSON or the response doesn't match the schema, the SDK retries up to `maxRetries` times. If all attempts fail, a `NoObjectGeneratedException` is thrown:

```php
use BengalStudio\AI\Exceptions\NoObjectGeneratedException;

try {
    $result = generateObject([
        'model' => $model,
        'schema' => $schema,
        'prompt' => '...',
        'maxRetries' => 3,
    ]);
} catch (NoObjectGeneratedException $e) {
    echo "Failed to generate: {$e->getMessage()}\n";
    echo "Raw text: {$e->text}\n";    // The raw model output
    echo "Reason: {$e->reason}\n";
}
```

## Options

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `model` | `LanguageModel` | Yes | The language model |
| `schema` | `array` | Yes | JSON Schema |
| `prompt` | `string` | No* | Text prompt |
| `system` | `string` | No | System message |
| `messages` | `Message[]` | No* | Conversation messages |
| `mode` | `string` | No | `'json'` (default) or `'tool'` |
| `schemaName` | `string` | No | Name for tool-mode schema |
| `schemaDescription` | `string` | No | Description for tool-mode schema |
| `maxRetries` | `int` | No | Retry count (default: 2) |
| `maxOutputTokens` | `int` | No | Max tokens |
| `temperature` | `float` | No | Sampling temperature |
| `seed` | `int` | No | Random seed |
| `providerOptions` | `array` | No | Provider-specific options |
| `onFinish` | `callable` | No | Callback on completion |
