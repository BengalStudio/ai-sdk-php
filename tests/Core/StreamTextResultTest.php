<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Core;

use BengalStudio\AI\Core\StreamTextResult;
use BengalStudio\AI\Types\LanguageModelUsage;
use PHPUnit\Framework\TestCase;

class StreamTextResultTest extends TestCase
{
    private function createStreamResult(array $chunks, ?\Closure $onFinish = null): StreamTextResult
    {
        return new StreamTextResult(
            streamFactory: function () use ($chunks) {
                foreach ($chunks as $chunk) {
                    yield $chunk;
                }
            },
            onFinish: $onFinish,
        );
    }

    // ─── getTextStream ───

    public function testGetTextStream(): void
    {
        $result = $this->createStreamResult([
            ['type' => 'text-delta', 'textDelta' => 'Hello'],
            ['type' => 'text-delta', 'textDelta' => ' world'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(10, 5)],
        ]);

        $text = '';
        foreach ($result->getTextStream() as $delta) {
            $text .= $delta;
        }

        $this->assertSame('Hello world', $text);
    }

    // ─── getText ───

    public function testGetText(): void
    {
        $result = $this->createStreamResult([
            ['type' => 'text-delta', 'textDelta' => 'Hello'],
            ['type' => 'text-delta', 'textDelta' => ' world'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(10, 5)],
        ]);

        $this->assertSame('Hello world', $result->getText());
    }

    // ─── getUsage ───

    public function testGetUsage(): void
    {
        $result = $this->createStreamResult([
            ['type' => 'text-delta', 'textDelta' => 'Hi'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(10, 5)],
        ]);

        $usage = $result->getUsage();
        $this->assertSame(10, $usage->inputTokens);
        $this->assertSame(5, $usage->outputTokens);
    }

    // ─── toUIMessageStreamResponse ───

    public function testToUIMessageStreamResponseFormatsDataStreamProtocol(): void
    {
        $result = $this->createStreamResult([
            ['type' => 'text-delta', 'textDelta' => 'Hello'],
            ['type' => 'text-delta', 'textDelta' => ' world'],
            ['type' => 'step-finish', 'step' => 0, 'finishReason' => 'stop'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(10, 5)],
        ]);

        $outputFile = tmpfile();
        $result->toUIMessageStreamResponse(output: $outputFile);

        fseek($outputFile, 0);
        $output = stream_get_contents($outputFile);
        fclose($outputFile);

        // Parse the SSE events
        $events = $this->parseSSEEvents($output);

        $this->assertNotEmpty($events);

        // First event: message start
        $this->assertSame('start', $events[0]['type']);
        $this->assertArrayHasKey('messageId', $events[0]);

        // Should have start-step event
        $types = array_column($events, 'type');
        $this->assertContains('start-step', $types);

        // Should have text-start event
        $this->assertContains('text-start', $types);

        // Should have text-delta events
        $textDeltas = array_filter($events, fn($e) => $e['type'] === 'text-delta');
        $this->assertCount(2, $textDeltas);
        $textDeltas = array_values($textDeltas);
        $this->assertSame('Hello', $textDeltas[0]['delta']);
        $this->assertSame(' world', $textDeltas[1]['delta']);

        // Should have text-end event
        $this->assertContains('text-end', $types);

        // Should have finish-step and finish events
        $this->assertContains('finish-step', $types);
        $this->assertContains('finish', $types);

        // Finish event should carry usage under `messageMetadata.totalUsage`
        // (NOT a top-level `totalUsage` key). The AI SDK v6 UI message chunk
        // schema is `z.strictObject({type:'finish', finishReason?, messageMetadata?})`
        // and unknown top-level keys cause the browser-side parse to fail —
        // which surfaces as a phantom error on the happy path in `useChat()`.
        $finishEvents = array_filter($events, fn($e) => $e['type'] === 'finish');
        $finishEvent = array_values($finishEvents)[0];
        $this->assertArrayNotHasKey('totalUsage', $finishEvent);
        $this->assertArrayHasKey('messageMetadata', $finishEvent);
        $this->assertArrayHasKey('totalUsage', $finishEvent['messageMetadata']);
        $this->assertSame(10, $finishEvent['messageMetadata']['totalUsage']['inputTokens']);
        $this->assertSame(5, $finishEvent['messageMetadata']['totalUsage']['outputTokens']);
        $this->assertSame(15, $finishEvent['messageMetadata']['totalUsage']['totalTokens']);

        // Should end with [DONE]
        $this->assertStringEndsWith("data: [DONE]\n\n", $output);
    }

    public function testToUIMessageStreamResponseWithToolCalls(): void
    {
        $result = $this->createStreamResult([
            ['type' => 'text-delta', 'textDelta' => 'Let me check.'],
            [
                'type' => 'tool-call',
                'toolCallId' => 'call_123',
                'toolName' => 'getWeather',
                'args' => ['city' => 'SF'],
                'step' => 0,
            ],
            [
                'type' => 'tool-result',
                'toolCallId' => 'call_123',
                'toolName' => 'getWeather',
                'result' => ['temp' => 72],
                'step' => 0,
            ],
            ['type' => 'step-finish', 'step' => 0],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(20, 10)],
        ]);

        $outputFile = tmpfile();
        $result->toUIMessageStreamResponse(output: $outputFile);

        fseek($outputFile, 0);
        $output = stream_get_contents($outputFile);
        fclose($outputFile);

        $events = $this->parseSSEEvents($output);
        $types = array_column($events, 'type');

        // Should have tool events
        $this->assertContains('tool-input-start', $types);
        $this->assertContains('tool-input-available', $types);
        $this->assertContains('tool-output-available', $types);

        // Text block should be closed before tool events
        $textEndIndex = array_search('text-end', $types);
        $toolStartIndex = array_search('tool-input-start', $types);
        $this->assertLessThan($toolStartIndex, $textEndIndex);

        // Tool output should come BEFORE finish-step (within the step)
        $toolOutputIndex = array_search('tool-output-available', $types);
        $finishStepIndex = array_search('finish-step', $types);
        $this->assertLessThan($finishStepIndex, $toolOutputIndex);

        // Tool output should have the result
        $toolOutputs = array_filter($events, fn($e) => $e['type'] === 'tool-output-available');
        $toolOutput = array_values($toolOutputs)[0];
        $this->assertSame('call_123', $toolOutput['toolCallId']);
        $this->assertSame(['temp' => 72], $toolOutput['output']);
    }

    public function testToUIMessageStreamResponseHandlesModelToolInputStart(): void
    {
        // Simulates model-level tool input streaming events
        // (tool-input-start, tool-input-delta, tool-call)
        $result = $this->createStreamResult([
            [
                'type' => 'tool-input-start',
                'id' => 'call_456',
                'toolName' => 'getWeather',
                'step' => 0,
            ],
            [
                'type' => 'tool-input-delta',
                'id' => 'call_456',
                'delta' => '{"city"',
                'step' => 0,
            ],
            [
                'type' => 'tool-input-delta',
                'id' => 'call_456',
                'delta' => ':"NYC"}',
                'step' => 0,
            ],
            [
                'type' => 'tool-call',
                'toolCallId' => 'call_456',
                'toolName' => 'getWeather',
                'args' => ['city' => 'NYC'],
                'step' => 0,
            ],
            [
                'type' => 'tool-result',
                'toolCallId' => 'call_456',
                'result' => 'sunny',
                'step' => 0,
            ],
            ['type' => 'step-finish', 'step' => 0],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(10, 5)],
        ]);

        $outputFile = tmpfile();
        $result->toUIMessageStreamResponse(output: $outputFile);

        fseek($outputFile, 0);
        $output = stream_get_contents($outputFile);
        fclose($outputFile);

        $events = $this->parseSSEEvents($output);
        $types = array_column($events, 'type');

        // Should have tool-input-start only once (from the model event,
        // NOT duplicated by the tool-call handler)
        $toolStartEvents = array_filter($events, fn($e) => $e['type'] === 'tool-input-start');
        $this->assertCount(1, $toolStartEvents);

        // Should have tool-input-delta events
        $toolDeltas = array_filter($events, fn($e) => $e['type'] === 'tool-input-delta');
        $this->assertCount(2, $toolDeltas);
        $deltas = array_values($toolDeltas);
        $this->assertSame('{"city"', $deltas[0]['inputTextDelta']);
        $this->assertSame(':"NYC"}', $deltas[1]['inputTextDelta']);

        // Should have tool-input-available with decoded args
        $toolAvailable = array_values(array_filter(
            $events, fn($e) => $e['type'] === 'tool-input-available'
        ));
        $this->assertCount(1, $toolAvailable);
        $this->assertSame(['city' => 'NYC'], $toolAvailable[0]['input']);

        // Should have tool-output-available
        $this->assertContains('tool-output-available', $types);

        // Ordering: tool-output-available before finish-step
        $toolOutputIndex = array_search('tool-output-available', $types);
        $finishStepIndex = array_search('finish-step', $types);
        $this->assertLessThan($finishStepIndex, $toolOutputIndex);
    }

    public function testToUIMessageStreamResponseToolInputAvailableHasDecodedArgs(): void
    {
        // When tool-call event has args already decoded (from StreamText)
        $result = $this->createStreamResult([
            [
                'type' => 'tool-call',
                'toolCallId' => 'call_789',
                'toolName' => 'search',
                'args' => ['query' => 'test', 'limit' => 10],
                'step' => 0,
            ],
            ['type' => 'step-finish', 'step' => 0],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(5, 3)],
        ]);

        $outputFile = tmpfile();
        $result->toUIMessageStreamResponse(output: $outputFile);

        fseek($outputFile, 0);
        $output = stream_get_contents($outputFile);
        fclose($outputFile);

        $events = $this->parseSSEEvents($output);

        // tool-input-available should have the full decoded args
        $toolAvailable = array_values(array_filter(
            $events, fn($e) => $e['type'] === 'tool-input-available'
        ));
        $this->assertCount(1, $toolAvailable);
        $this->assertSame(['query' => 'test', 'limit' => 10], $toolAvailable[0]['input']);
    }

    public function testToUIMessageStreamResponseWithMessageMetadata(): void
    {
        $result = $this->createStreamResult([
            ['type' => 'text-delta', 'textDelta' => 'Hello'],
            ['type' => 'step-finish', 'step' => 0],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(10, 5)],
        ]);

        $outputFile = tmpfile();
        $result->toUIMessageStreamResponse(
            options: [
                'messageMetadata' => function (array $part): ?array {
                    if ($part['type'] === 'start') {
                        return ['model' => 'gpt-4.1'];
                    }
                    if ($part['type'] === 'finish') {
                        // The auto-populated `totalUsage` block is already
                        // attached to `messageMetadata` by the time the callback
                        // runs, so callers read it from there (not from a
                        // top-level `totalUsage` key, which no longer exists —
                        // see the AI SDK v6 schema-compatibility fix).
                        return [
                            'totalTokens' => $part['messageMetadata']['totalUsage']['totalTokens'] ?? 0,
                        ];
                    }
                    return null;
                },
            ],
            output: $outputFile,
        );

        fseek($outputFile, 0);
        $output = stream_get_contents($outputFile);
        fclose($outputFile);

        $events = $this->parseSSEEvents($output);

        // Start event should have messageMetadata (key matches AI SDK v6 schema)
        $this->assertSame('start', $events[0]['type']);
        $this->assertSame(['model' => 'gpt-4.1'], $events[0]['messageMetadata']);
        $this->assertArrayNotHasKey('metadata', $events[0]);

        // Finish event should have messageMetadata containing BOTH the
        // auto-populated `totalUsage` block and the callback-supplied keys.
        // Caller-supplied keys win on key conflict.
        $finishEvents = array_filter($events, fn($e) => $e['type'] === 'finish');
        $finishEvent = array_values($finishEvents)[0];
        $this->assertArrayNotHasKey('metadata', $finishEvent);
        $this->assertArrayHasKey('messageMetadata', $finishEvent);
        // Caller's key:
        $this->assertSame(15, $finishEvent['messageMetadata']['totalTokens']);
        // Auto-populated `totalUsage` survives the merge:
        $this->assertArrayHasKey('totalUsage', $finishEvent['messageMetadata']);
        $this->assertSame(10, $finishEvent['messageMetadata']['totalUsage']['inputTokens']);
    }

    public function testToUIMessageStreamResponseWithError(): void
    {
        $result = new StreamTextResult(
            streamFactory: function () {
                yield ['type' => 'text-delta', 'textDelta' => 'Hello'];
                throw new \RuntimeException('API error');
            },
        );

        $outputFile = tmpfile();
        $result->toUIMessageStreamResponse(
            options: [
                'onError' => fn(\Throwable $e) => 'Custom error: ' . $e->getMessage(),
            ],
            output: $outputFile,
        );

        fseek($outputFile, 0);
        $output = stream_get_contents($outputFile);
        fclose($outputFile);

        $events = $this->parseSSEEvents($output);
        $types = array_column($events, 'type');
        $this->assertContains('error', $types);

        $errorEvents = array_filter($events, fn($e) => $e['type'] === 'error');
        $errorEvent = array_values($errorEvents)[0];
        $this->assertSame('Custom error: API error', $errorEvent['errorText']);
    }

    // ─── pipeTextStreamToResponse ───

    public function testPipeTextStreamToResponsePlainText(): void
    {
        $result = $this->createStreamResult([
            ['type' => 'text-delta', 'textDelta' => 'Hello'],
            ['type' => 'text-delta', 'textDelta' => ' world'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(10, 5)],
        ]);

        $outputFile = tmpfile();
        $result->pipeTextStreamToResponse(output: $outputFile, format: 'text');

        fseek($outputFile, 0);
        $output = stream_get_contents($outputFile);
        fclose($outputFile);

        $this->assertSame('Hello world', $output);
    }

    public function testPipeTextStreamToResponseSSE(): void
    {
        $result = $this->createStreamResult([
            ['type' => 'text-delta', 'textDelta' => 'Hello'],
            ['type' => 'text-delta', 'textDelta' => ' world'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(10, 5)],
        ]);

        $outputFile = tmpfile();
        $result->pipeTextStreamToResponse(output: $outputFile, format: 'sse');

        fseek($outputFile, 0);
        $output = stream_get_contents($outputFile);
        fclose($outputFile);

        $this->assertStringContainsString('data: "Hello"', $output);
        $this->assertStringContainsString('data: " world"', $output);
        $this->assertStringEndsWith("data: [DONE]\n\n", $output);
    }

    // ─── toTextStreamResponse ───

    public function testToTextStreamResponse(): void
    {
        $result = $this->createStreamResult([
            ['type' => 'text-delta', 'textDelta' => 'Hello'],
            ['type' => 'text-delta', 'textDelta' => ' world'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(10, 5)],
        ]);

        $outputFile = tmpfile();
        $result->toTextStreamResponse(output: $outputFile);

        fseek($outputFile, 0);
        $output = stream_get_contents($outputFile);
        fclose($outputFile);

        $this->assertSame('Hello world', $output);
    }

    // ─── toDataStream ───

    public function testToDataStream(): void
    {
        $result = $this->createStreamResult([
            ['type' => 'text-delta', 'textDelta' => 'Hi'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        $events = [];
        foreach ($result->toDataStream() as $event) {
            $events[] = $event;
        }

        $this->assertCount(3, $events); // 2 chunks + [DONE]
        $this->assertStringStartsWith('data: ', $events[0]);
        $this->assertSame("data: [DONE]\n\n", $events[2]);
    }

    // ─── onFinish callback ───

    public function testOnFinishCallback(): void
    {
        $captured = null;
        $result = $this->createStreamResult(
            [
                ['type' => 'text-delta', 'textDelta' => 'Hi'],
                ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(10, 5)],
            ],
            onFinish: function (array $data) use (&$captured) {
                $captured = $data;
            },
        );

        $result->getText();

        $this->assertNotNull($captured);
        $this->assertSame('Hi', $captured['text']);
        $this->assertInstanceOf(LanguageModelUsage::class, $captured['usage']);
    }

    // ─── AI SDK v6 strict-schema invariant ───

    /**
     * Contract test: every chunk emitted by `toUIMessageStreamResponse()`
     * must carry **only** keys that the AI SDK v6 UI message chunk schema
     * recognises for its `type`.
     *
     * The schema is `z.strictObject(...)` per variant — unknown keys cause
     * the browser-side parse in `DefaultChatTransport.processResponseStream`
     * to throw, which `AbstractChat.makeRequest` stores as `state.error` and
     * `useChat()` surfaces. That's how a phantom "Chat is temporarily
     * unavailable" banner can fire on a successful response if any chunk
     * here ever grows an extra top-level field.
     *
     * The fixture exercises the dense path (text + tool call + finish with
     * usage); extend the allow-list and the fixture together if new chunk
     * types are added.
     */
    public function testToUIMessageStreamResponseChunksMatchAiSdkV6StrictSchema(): void
    {
        $allowedKeysByType = [
            'start'                 => ['type', 'messageId', 'messageMetadata'],
            'start-step'            => ['type'],
            'finish-step'           => ['type'],
            'finish'                => ['type', 'finishReason', 'messageMetadata'],
            'text-start'            => ['type', 'id', 'providerMetadata'],
            'text-delta'            => ['type', 'id', 'delta', 'providerMetadata'],
            'text-end'              => ['type', 'id', 'providerMetadata'],
            'reasoning-start'       => ['type', 'id', 'providerMetadata'],
            'reasoning-delta'       => ['type', 'id', 'delta', 'providerMetadata'],
            'reasoning-end'         => ['type', 'id', 'providerMetadata'],
            'tool-input-start'      => ['type', 'toolCallId', 'toolName', 'providerExecuted', 'providerMetadata', 'dynamic', 'title'],
            'tool-input-delta'      => ['type', 'toolCallId', 'inputTextDelta'],
            'tool-input-available'  => ['type', 'toolCallId', 'toolName', 'input', 'providerExecuted', 'providerMetadata', 'dynamic', 'title'],
            'tool-output-available' => ['type', 'toolCallId', 'output', 'providerExecuted', 'dynamic', 'preliminary'],
            'error'                 => ['type', 'errorText'],
        ];

        $result = $this->createStreamResult([
            ['type' => 'text-delta', 'textDelta' => 'Looking it up.'],
            // Model-level tool input streaming so the strict guard also runs
            // over the emitted `tool-input-start` / `tool-input-delta` wire
            // chunks (not just the `tool-call`-derived `tool-input-available`).
            ['type' => 'tool-input-start', 'id' => 'call_strict', 'toolName' => 'getWeather', 'step' => 0],
            ['type' => 'tool-input-delta', 'id' => 'call_strict', 'delta' => '{"city"', 'step' => 0],
            ['type' => 'tool-input-delta', 'id' => 'call_strict', 'delta' => ':"BD"}', 'step' => 0],
            [
                'type' => 'tool-call',
                'toolCallId' => 'call_strict',
                'toolName' => 'getWeather',
                'args' => ['city' => 'BD'],
                'step' => 0,
            ],
            [
                'type' => 'tool-result',
                'toolCallId' => 'call_strict',
                'result' => ['temp' => 30],
                'step' => 0,
            ],
            ['type' => 'step-finish', 'step' => 0, 'finishReason' => 'tool-calls'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(42, 17)],
        ]);

        $outputFile = tmpfile();
        $result->toUIMessageStreamResponse(output: $outputFile);

        fseek($outputFile, 0);
        $output = stream_get_contents($outputFile);
        fclose($outputFile);

        $events = $this->parseSSEEvents($output);
        $this->assertNotEmpty($events);

        foreach ($events as $i => $event) {
            $type = $event['type'] ?? null;
            $this->assertNotNull($type, "Event #{$i} has no `type` key: " . json_encode($event));
            $this->assertArrayHasKey(
                $type,
                $allowedKeysByType,
                "Event #{$i} type `{$type}` is not in the allow-list. Update the test or the emitter."
            );

            $extra = array_diff(array_keys($event), $allowedKeysByType[$type]);
            $this->assertSame(
                [],
                $extra,
                "Event #{$i} of type `{$type}` has unrecognised top-level key(s): "
                . implode(', ', $extra)
                . ' — these will be rejected by `@ai-sdk/react`\'s strict zod parse.'
            );
        }

        // Regression guard for the specific bug this test was added to catch:
        // the `finish` chunk used to carry a top-level `totalUsage`.
        $finishEvents = array_values(array_filter(
            $events,
            fn($e) => ($e['type'] ?? '') === 'finish'
        ));
        $this->assertCount(1, $finishEvents);
        $this->assertArrayNotHasKey('totalUsage', $finishEvents[0]);
        $this->assertSame(
            42,
            $finishEvents[0]['messageMetadata']['totalUsage']['inputTokens'] ?? null
        );
    }

    // ─── Helpers ───

    /**
     * Parse SSE output into an array of decoded JSON events.
     */
    private function parseSSEEvents(string $output): array
    {
        $events = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data: ')) {
                $data = substr($line, 6);
                if ($data === '[DONE]') {
                    continue;
                }
                $decoded = json_decode($data, true);
                if ($decoded !== null) {
                    $events[] = $decoded;
                }
            }
        }

        return $events;
    }
}
