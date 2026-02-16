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

        // Finish event should contain usage
        $finishEvents = array_filter($events, fn($e) => $e['type'] === 'finish');
        $finishEvent = array_values($finishEvents)[0];
        $this->assertArrayHasKey('totalUsage', $finishEvent);
        $this->assertSame(10, $finishEvent['totalUsage']['inputTokens']);

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

        // Tool output should have the result
        $toolOutputs = array_filter($events, fn($e) => $e['type'] === 'tool-output-available');
        $toolOutput = array_values($toolOutputs)[0];
        $this->assertSame('call_123', $toolOutput['toolCallId']);
        $this->assertSame(['temp' => 72], $toolOutput['output']);
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
                        return ['totalTokens' => $part['totalUsage']['totalTokens'] ?? 0];
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

        // Start event should have metadata
        $this->assertSame('start', $events[0]['type']);
        $this->assertSame(['model' => 'gpt-4.1'], $events[0]['metadata']);

        // Finish event should have metadata
        $finishEvents = array_filter($events, fn($e) => $e['type'] === 'finish');
        $finishEvent = array_values($finishEvents)[0];
        $this->assertArrayHasKey('metadata', $finishEvent);
        $this->assertSame(15, $finishEvent['metadata']['totalTokens']);
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
