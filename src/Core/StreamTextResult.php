<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Types\LanguageModelUsage;
use BengalStudio\AI\Util\IdGenerator;

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

    // ─────────────────────────────────────────────────────────────
    //  Prepare HTTP streaming environment
    // ─────────────────────────────────────────────────────────────

    /**
     * Prepare the PHP environment for streaming output.
     *
     * Disables output buffering and compression, clears any existing
     * output buffer levels (e.g. from WordPress or other frameworks).
     *
     * @param bool $clearBuffers Whether to clear output buffers (skip when writing to a file resource).
     */
    private function prepareStreamingEnvironment(bool $clearBuffers = true): void
    {
        @ini_set('output_buffering', 'Off');
        @ini_set('zlib.output_compression', false);

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        if ($clearBuffers) {
            // Clear all output buffers that may have been opened by
            // WordPress, PHP, or any other framework/middleware.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }
    }

    /**
     * Send common streaming headers. Must be called before any output.
     *
     * @param array<string, string> $extra Additional headers to send.
     */
    private function sendStreamingHeaders(array $extra = []): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($extra as $name => $value) {
            header("{$name}: {$value}");
        }

        // Disable nginx/proxy buffering
        header('X-Accel-Buffering: no');
    }

    // ─────────────────────────────────────────────────────────────
    //  Text Stream Protocol
    // ─────────────────────────────────────────────────────────────

    /**
     * Pipe the text stream to a PHP output stream.
     *
     * Useful for Server-Sent Events (SSE) or chunked HTTP responses.
     * This method clears all PHP output buffers and disables buffering
     * to ensure each chunk is sent to the client immediately.
     *
     * IMPORTANT: When used inside a WordPress REST API callback or any
     * framework that captures output, call exit() after this method
     * to prevent the framework from sending its own response.
     *
     * @param resource|null $output Defaults to php://output if null.
     * @param string $format 'text' for plain text, 'sse' for Server-Sent Events.
     */
    public function pipeTextStreamToResponse($output = null, string $format = 'text'): void
    {
        $useStdout = ($output === null);
        $this->prepareStreamingEnvironment(clearBuffers: $useStdout);

        if ($useStdout) {
            $output = fopen('php://output', 'w');
        }

        if ($format === 'sse') {
            $this->sendStreamingHeaders([
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
            ]);
        } else {
            $this->sendStreamingHeaders([
                'Content-Type' => 'text/plain; charset=utf-8',
                'Transfer-Encoding' => 'chunked',
            ]);
        }

        foreach ($this->getTextStream() as $textDelta) {
            if ($format === 'sse') {
                fwrite($output, "data: " . json_encode($textDelta) . "\n\n");
            } else {
                fwrite($output, $textDelta);
            }
            flush();
        }

        if ($format === 'sse') {
            fwrite($output, "data: [DONE]\n\n");
            flush();
        }
    }

    /**
     * Return a text stream HTTP response.
     *
     * Streams plain text deltas directly. Compatible with `@ai-sdk/react`'s
     * `TextStreamChatTransport`.
     *
     * Usage with `@ai-sdk/react`:
     * ```js
     * import { useChat } from '@ai-sdk/react';
     * import { TextStreamChatTransport } from 'ai';
     *
     * const { messages, sendMessage } = useChat({
     *   transport: new TextStreamChatTransport({ api: '/api/chat' }),
     * });
     * ```
     *
     * IMPORTANT: Call exit() after this method when used inside WordPress
     * REST API callbacks or similar frameworks.
     *
     * @param resource|null $output Defaults to php://output if null.
     */
    public function toTextStreamResponse($output = null): void
    {
        $useStdout = ($output === null);
        $this->prepareStreamingEnvironment(clearBuffers: $useStdout);

        if ($useStdout) {
            $output = fopen('php://output', 'w');
        }

        $this->sendStreamingHeaders([
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);

        foreach ($this->getTextStream() as $textDelta) {
            fwrite($output, $textDelta);
            flush();
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Data Stream Protocol (UI Message Stream)
    // ─────────────────────────────────────────────────────────────

    /**
     * Return a UI Message Stream HTTP response.
     *
     * Streams events following the AI SDK Data Stream Protocol (v1),
     * compatible with `@ai-sdk/react`'s `useChat` hook (default transport).
     *
     * The Data Stream Protocol uses Server-Sent Events (SSE) and includes:
     * - Message start/finish events
     * - Step start/finish events
     * - Text start/delta/end events
     * - Tool call input streaming events
     * - Tool output events
     *
     * Usage with `@ai-sdk/react`:
     * ```js
     * import { useChat } from '@ai-sdk/react';
     *
     * // Data stream is the default — no special transport needed:
     * const { messages, sendMessage } = useChat();
     * ```
     *
     * IMPORTANT: Call exit() after this method when used inside WordPress
     * REST API callbacks or similar frameworks.
     *
     * @param array $options {
     *   @type \Closure|null $messageMetadata  fn(array $part): ?array — attach metadata per event.
     *   @type bool          $sendReasoning    Forward reasoning parts (default: false).
     *   @type bool          $sendSources      Forward source parts (default: false).
     *   @type \Closure|null $onError          fn(mixed $error): string — custom error message.
     * }
     * @param resource|null $output Defaults to php://output if null.
     */
    public function toUIMessageStreamResponse(array $options = [], $output = null): void
    {
        $useStdout = ($output === null);
        $this->prepareStreamingEnvironment(clearBuffers: $useStdout);

        if ($useStdout) {
            $output = fopen('php://output', 'w');
        }

        $this->sendStreamingHeaders([
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'x-vercel-ai-ui-message-stream' => 'v1',
        ]);

        $messageMetadata = $options['messageMetadata'] ?? null;
        $sendReasoning = $options['sendReasoning'] ?? false;
        $sendSources = $options['sendSources'] ?? false;
        $onError = $options['onError'] ?? null;

        $messageId = IdGenerator::createId('msg');
        $textBlockId = null;
        $inTextBlock = false;
        $messageSent = false;
        $stepOpen = false;

        try {
            foreach ($this->getFullStream() as $chunk) {
                $type = $chunk['type'] ?? '';

                // Send message start on first chunk
                if (!$messageSent) {
                    $startPart = ['type' => 'start', 'messageId' => $messageId];
                    if ($messageMetadata) {
                        $meta = $messageMetadata($startPart);
                        if ($meta !== null) {
                            $startPart['metadata'] = $meta;
                        }
                    }
                    $this->writeSSE($output, $startPart);
                    $messageSent = true;
                }

                switch ($type) {
                    case 'text-delta':
                        // Open a step if not already open
                        if (!$stepOpen) {
                            $this->writeSSE($output, ['type' => 'start-step']);
                            $stepOpen = true;
                        }

                        // Open text block if needed
                        if (!$inTextBlock) {
                            $textBlockId = IdGenerator::createId('text');
                            $this->writeSSE($output, [
                                'type' => 'text-start',
                                'id' => $textBlockId,
                            ]);
                            $inTextBlock = true;
                        }

                        $this->writeSSE($output, [
                            'type' => 'text-delta',
                            'id' => $textBlockId,
                            'delta' => $chunk['textDelta'] ?? '',
                        ]);
                        break;

                    case 'reasoning':
                        if (!$sendReasoning) {
                            break;
                        }
                        if (!$stepOpen) {
                            $this->writeSSE($output, ['type' => 'start-step']);
                            $stepOpen = true;
                        }
                        $reasoningId = $chunk['id'] ?? IdGenerator::createId('reasoning');
                        $this->writeSSE($output, [
                            'type' => 'reasoning-start',
                            'id' => $reasoningId,
                        ]);
                        $this->writeSSE($output, [
                            'type' => 'reasoning-delta',
                            'id' => $reasoningId,
                            'delta' => $chunk['text'] ?? $chunk['textDelta'] ?? '',
                        ]);
                        $this->writeSSE($output, [
                            'type' => 'reasoning-end',
                            'id' => $reasoningId,
                        ]);
                        break;

                    case 'source-url':
                    case 'source-document':
                        if (!$sendSources) {
                            break;
                        }
                        $this->writeSSE($output, $chunk);
                        break;

                    case 'tool-call':
                        // Close text block if open
                        if ($inTextBlock) {
                            $this->writeSSE($output, [
                                'type' => 'text-end',
                                'id' => $textBlockId,
                            ]);
                            $inTextBlock = false;
                        }

                        if (!$stepOpen) {
                            $this->writeSSE($output, ['type' => 'start-step']);
                            $stepOpen = true;
                        }

                        $toolCallId = $chunk['toolCallId'] ?? '';
                        $toolName = $chunk['toolName'] ?? '';
                        $args = $chunk['args'] ?? [];

                        // Send tool input complete (available)
                        $this->writeSSE($output, [
                            'type' => 'tool-input-start',
                            'toolCallId' => $toolCallId,
                            'toolName' => $toolName,
                        ]);
                        $this->writeSSE($output, [
                            'type' => 'tool-input-available',
                            'toolCallId' => $toolCallId,
                            'toolName' => $toolName,
                            'input' => $args,
                        ]);
                        break;

                    case 'tool-call-delta':
                        if (!$stepOpen) {
                            $this->writeSSE($output, ['type' => 'start-step']);
                            $stepOpen = true;
                        }
                        $this->writeSSE($output, [
                            'type' => 'tool-input-delta',
                            'toolCallId' => $chunk['toolCallId'] ?? '',
                            'inputTextDelta' => $chunk['inputTextDelta'] ?? $chunk['argsTextDelta'] ?? '',
                        ]);
                        break;

                    case 'tool-result':
                        $this->writeSSE($output, [
                            'type' => 'tool-output-available',
                            'toolCallId' => $chunk['toolCallId'] ?? '',
                            'output' => $chunk['result'] ?? $chunk['output'] ?? null,
                        ]);
                        break;

                    case 'step-finish':
                        // Close text block if open
                        if ($inTextBlock) {
                            $this->writeSSE($output, [
                                'type' => 'text-end',
                                'id' => $textBlockId,
                            ]);
                            $inTextBlock = false;
                        }

                        if ($stepOpen) {
                            $finishStepPart = ['type' => 'finish-step'];
                            if ($messageMetadata) {
                                $meta = $messageMetadata($finishStepPart);
                                if ($meta !== null) {
                                    $finishStepPart['metadata'] = $meta;
                                }
                            }
                            $this->writeSSE($output, $finishStepPart);
                            $stepOpen = false;
                        }
                        break;

                    case 'finish':
                        // Close text block if still open
                        if ($inTextBlock) {
                            $this->writeSSE($output, [
                                'type' => 'text-end',
                                'id' => $textBlockId,
                            ]);
                            $inTextBlock = false;
                        }

                        // Close step if still open
                        if ($stepOpen) {
                            $this->writeSSE($output, ['type' => 'finish-step']);
                            $stepOpen = false;
                        }

                        $finishPart = ['type' => 'finish'];
                        if (isset($chunk['totalUsage'])) {
                            $usage = $chunk['totalUsage'];
                            if ($usage instanceof LanguageModelUsage) {
                                $finishPart['totalUsage'] = [
                                    'inputTokens' => $usage->inputTokens,
                                    'outputTokens' => $usage->outputTokens,
                                    'totalTokens' => $usage->total(),
                                ];
                            }
                        }
                        if ($messageMetadata) {
                            $meta = $messageMetadata($finishPart);
                            if ($meta !== null) {
                                $finishPart['metadata'] = $meta;
                            }
                        }
                        $this->writeSSE($output, $finishPart);
                        break;

                    case 'error':
                        $errorText = $chunk['errorText'] ?? 'An error occurred.';
                        $this->writeSSE($output, [
                            'type' => 'error',
                            'errorText' => $errorText,
                        ]);
                        break;
                }
            }
        } catch (\Throwable $e) {
            $errorMessage = $onError ? $onError($e) : 'An error occurred.';
            $this->writeSSE($output, [
                'type' => 'error',
                'errorText' => $errorMessage,
            ]);
        }

        // Terminate the stream
        fwrite($output, "data: [DONE]\n\n");
        flush();
    }

    /**
     * Write a single SSE event to the output stream.
     *
     * @param resource $output
     * @param array $data
     */
    private function writeSSE($output, array $data): void
    {
        fwrite($output, "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
        flush();
    }

    // ─────────────────────────────────────────────────────────────
    //  Legacy / convenience
    // ─────────────────────────────────────────────────────────────

    /**
     * Convert the entire stream to a data stream (SSE format) generator.
     *
     * @deprecated Use toUIMessageStreamResponse() for @ai-sdk/react compatibility.
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
