<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Types\LanguageModelUsage;

/**
 * Result of a streamText call.
 *
 * Provides access to the stream, and convenience methods
 * to consume the full text or other aggregated data.
 *
 * Mirrors Vercel AI SDK's StreamTextResult.
 */
class StreamTextResult
{
    private \Closure $streamFactory;
    private ?\Closure $onFinish;
    private ?\Generator $stream = null;
    private ?string $resolvedText = null;
    private ?LanguageModelUsage $resolvedUsage = null;

    public function __construct(
        \Closure $streamFactory,
        ?\Closure $onFinish = null,
    ) {
        $this->streamFactory = $streamFactory;
        $this->onFinish = $onFinish;
    }

    /**
     * Get the full stream of events as a Generator.
     *
     * Each yielded item is an associative array with a 'type' key.
     * Types include: 'text-delta', 'tool-call', 'tool-result',
     * 'step-finish', 'finish'.
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
     * Get only the text deltas as a Generator of strings.
     *
     * Useful for streaming text to the client.
     *
     * @return \Generator<string>
     */
    public function getTextStream(): \Generator
    {
        foreach ($this->getFullStream() as $chunk) {
            if (($chunk['type'] ?? '') === 'text-delta') {
                yield $chunk['textDelta'];
            }
        }
    }

    /**
     * Consume the entire stream and return the concatenated text.
     */
    public function getText(): string
    {
        if ($this->resolvedText !== null) {
            return $this->resolvedText;
        }

        $this->resolvedText = '';

        foreach ($this->getFullStream() as $chunk) {
            $type = $chunk['type'] ?? '';

            if ($type === 'text-delta') {
                $this->resolvedText .= $chunk['textDelta'];
            }

            if ($type === 'finish') {
                $this->resolvedUsage = $chunk['totalUsage'] ?? null;
            }
        }

        if ($this->onFinish !== null) {
            ($this->onFinish)([
                'text' => $this->resolvedText,
                'usage' => $this->resolvedUsage,
            ]);
        }

        return $this->resolvedText;
    }

    /**
     * Get the total usage after consuming the stream.
     */
    public function getUsage(): ?LanguageModelUsage
    {
        if ($this->resolvedUsage === null) {
            $this->getText(); // Consumes stream to resolve usage
        }
        return $this->resolvedUsage;
    }

    /**
     * Pipe the text stream to a PHP output stream.
     *
     * Useful for Server-Sent Events (SSE) or chunked HTTP responses.
     *
     * @param resource|null $output Defaults to php://output if null.
     * @param string $format 'text' for plain text, 'sse' for Server-Sent Events.
     */
    public function pipeTextStreamToResponse($output = null, string $format = 'text'): void
    {
        if ($output === null) {
            $output = fopen('php://output', 'w');
        }

        // Set headers for streaming
        if (!headers_sent()) {
            if ($format === 'sse') {
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('Connection: keep-alive');
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                header('Transfer-Encoding: chunked');
            }
        }

        foreach ($this->getTextStream() as $textDelta) {
            if ($format === 'sse') {
                fwrite($output, "data: " . json_encode($textDelta) . "\n\n");
            } else {
                fwrite($output, $textDelta);
            }

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }

        if ($format === 'sse') {
            fwrite($output, "data: [DONE]\n\n");
        }
    }

    /**
     * Convert the entire stream to a data stream response (SSE format).
     *
     * @return \Generator<string> Each yielded string is an SSE event line.
     */
    public function toDataStream(): \Generator
    {
        foreach ($this->getFullStream() as $chunk) {
            yield "data: " . json_encode($chunk) . "\n\n";
        }
        yield "data: [DONE]\n\n";
    }
}
