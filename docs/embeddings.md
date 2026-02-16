# Embeddings Guide

Embeddings convert text into numerical vectors that capture semantic meaning. Use them for similarity search, clustering, classification, and RAG (Retrieval-Augmented Generation).

## Quick Start

### Single Embedding

```php
use function BengalStudio\AI\embed;

$result = embed([
    'model' => $embeddingModel,
    'value' => 'The quick brown fox jumps over the lazy dog.',
]);

$vector = $result->embedding;            // float[]
$dims = $result->getDimensions();         // e.g. 1536
$tokens = $result->usage->tokens;         // Token usage
```

### Multiple Embeddings

```php
use function BengalStudio\AI\embedMany;

$result = embedMany([
    'model' => $embeddingModel,
    'values' => [
        'Cats are great pets.',
        'Dogs are loyal companions.',
        'PHP is a programming language.',
    ],
]);

// $result->embeddings is an array of float[] vectors
echo count($result->embeddings);          // 3
echo count($result->embeddings[0]);       // e.g. 1536
echo $result->usage->tokens;             // Total tokens used
```

## Similarity Search

Use `cosineSimilarity` to compare embeddings:

```php
use function BengalStudio\AI\cosineSimilarity;
use function BengalStudio\AI\embedMany;

$result = embedMany([
    'model' => $embeddingModel,
    'values' => [
        'I love programming in PHP.',
        'PHP is my favorite language.',
        'The weather is nice today.',
    ],
]);

// Compare first and second (semantically similar)
$sim1 = cosineSimilarity($result->embeddings[0], $result->embeddings[1]);

// Compare first and third (semantically different)
$sim2 = cosineSimilarity($result->embeddings[0], $result->embeddings[2]);

echo "Similar: $sim1\n";   // ~0.85+
echo "Different: $sim2\n"; // ~0.3-0.5
```

Return values range from -1 (opposite) to 1 (identical).

## Automatic Chunking

`embedMany()` automatically splits large batches based on the model's `maxEmbeddingsPerCall()`. For example, if the model supports 2048 embeddings per call and you pass 5000 values, three API calls are made transparently.

## Options

### `embed()`

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `model` | `EmbeddingModel` | Yes | The embedding model |
| `value` | `string` | Yes | Text to embed |
| `maxRetries` | `int` | No | Retry count (default: 2) |
| `headers` | `array` | No | Additional HTTP headers |
| `providerOptions` | `array` | No | Provider-specific options |

### `embedMany()`

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `model` | `EmbeddingModel` | Yes | The embedding model |
| `values` | `string[]` | Yes | Texts to embed |
| `maxRetries` | `int` | No | Retry count (default: 2) |
| `headers` | `array` | No | Additional HTTP headers |
| `providerOptions` | `array` | No | Provider-specific options |

## Result Objects

### `EmbedResult`

| Property | Type | Description |
|----------|------|-------------|
| `embedding` | `float[]` | The embedding vector |
| `usage` | `EmbeddingModelUsage` | Token usage |

Method: `getDimensions(): int`

### `EmbedManyResult`

| Property | Type | Description |
|----------|------|-------------|
| `embeddings` | `float[][]` | Array of embedding vectors |
| `usage` | `EmbeddingModelUsage` | Total token usage |

## Using with RAG

A common pattern is to embed documents at index time and queries at search time:

```php
// Index time — embed your documents
$docs = ['Document 1 text...', 'Document 2 text...', /* ... */];
$docEmbeddings = embedMany(['model' => $model, 'values' => $docs]);

// Store $docEmbeddings->embeddings alongside your documents in a vector DB

// Search time — embed the query and find similar documents
$queryResult = embed(['model' => $model, 'value' => 'What is X?']);
$queryVector = $queryResult->embedding;

// Compare with stored embeddings using cosineSimilarity()
// or use your vector database's native search
```
