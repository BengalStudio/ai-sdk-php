<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Types\LanguageModelUsage;

/**
 * Result of a streamObject call.
 *
 * Provides access to the stream of partial objects.
 *
 * Mirrors Vercel AI SDK's StreamObjectResult.
 */
class StreamObjectResult
{
    private \Closure $streamFactory;
    private array $schema;
    private ?\Closure $onFinish;
    private ?\Generator $stream = null;
    private ?array $resolvedObject = null;
    private ?LanguageModelUsage $resolvedUsage = null;

    public function __construct(
        \Closure $streamFactory,
        array $schema,
        ?\Closure $onFinish = null,
    ) {
        $this->streamFactory = $streamFactory;
        $this->schema = $schema;
        $this->onFinish = $onFinish;
    }

    /**
     * Get the full stream of events.
     *
     * @return \Generator
     */
    public function getFullStream(): \Generator
    {
        if ($this->stream === null) {
            $this->stream = ($this->streamFactory)();
        }
        return $this->stream;
    }

    /**
     * Get a stream of partial objects as they are being generated.
     *
     * @return \Generator<array|null>
     */
    public function getPartialObjectStream(): \Generator
    {
        foreach ($this->getFullStream() as $chunk) {
            $type = $chunk['type'] ?? '';

            if ($type === 'object-delta' && isset($chunk['partialObject'])) {
                yield $chunk['partialObject'];
            } elseif ($type === 'object') {
                yield $chunk['object'];
            }
        }
    }

    /**
     * Consume the entire stream and return the final object.
     *
     * @return array
     */
    public function getObject(): array
    {
        if ($this->resolvedObject !== null) {
            return $this->resolvedObject;
        }

        $lastPartial = null;

        foreach ($this->getFullStream() as $chunk) {
            $type = $chunk['type'] ?? '';

            if ($type === 'object-delta' && isset($chunk['partialObject'])) {
                $lastPartial = $chunk['partialObject'];
            } elseif ($type === 'object') {
                $lastPartial = $chunk['object'];
            } elseif ($type === 'finish') {
                $this->resolvedUsage = $chunk['usage'] ?? null;
            }
        }

        $this->resolvedObject = $lastPartial ?? [];

        if ($this->onFinish !== null) {
            ($this->onFinish)(new GenerateObjectResult(
                object: $this->resolvedObject,
                rawText: json_encode($this->resolvedObject),
                finishReason: \BengalStudio\AI\Types\FinishReason::Stop,
                usage: $this->resolvedUsage ?? new LanguageModelUsage(),
            ));
        }

        return $this->resolvedObject;
    }

    /**
     * Get usage after consuming the stream.
     */
    public function getUsage(): ?LanguageModelUsage
    {
        if ($this->resolvedUsage === null) {
            $this->getObject();
        }
        return $this->resolvedUsage;
    }
}
