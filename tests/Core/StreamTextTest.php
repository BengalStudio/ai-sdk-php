<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Core;

use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Core\StreamText;
use BengalStudio\AI\Types\LanguageModelCallOptions;
use BengalStudio\AI\Types\LanguageModelGenerateResult;
use BengalStudio\AI\Types\LanguageModelStreamResult;
use BengalStudio\AI\Types\LanguageModelUsage;
use PHPUnit\Framework\TestCase;

/**
 * Pins StreamText::createStream()'s handling of model-level tool input
 * streaming parts (tool-input-start / -delta / -end).
 *
 * Contract (Vercel stream-text.ts parity):
 *  - the parts are forwarded downstream with their language-model keys
 *    (`id`/`delta`) intact — the rename to `toolCallId`/`inputTextDelta`
 *    is the serializer's job, not the core loop's;
 *  - `onChunk` fires for `tool-input-start` and `tool-input-delta`, but
 *    NOT for `tool-input-end`.
 */
class StreamTextTest extends TestCase
{
    /**
     * Run a StreamText over a fake model that replays the given scripted
     * model-level parts.
     *
     * @param array<int, array<string, mixed>> $modelParts
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     *         [yielded full-stream parts, types passed to onChunk]
     */
    private function driveStream(array $modelParts): array
    {
        $model = new class($modelParts) implements LanguageModel {
            /** @param array<int, array<string, mixed>> $parts */
            public function __construct(private readonly array $parts)
            {
            }

            public function specificationVersion(): string
            {
                return 'v3';
            }

            public function provider(): string
            {
                return 'fake';
            }

            public function modelId(): string
            {
                return 'fake-model';
            }

            public function doGenerate(LanguageModelCallOptions $options): LanguageModelGenerateResult
            {
                throw new \LogicException('doGenerate is not exercised by StreamTextTest.');
            }

            public function doStream(LanguageModelCallOptions $options): LanguageModelStreamResult
            {
                $parts = $this->parts;
                $gen = (function () use ($parts) {
                    foreach ($parts as $part) {
                        yield $part;
                    }
                })();

                return new LanguageModelStreamResult(stream: $gen);
            }
        };

        $onChunkTypes = [];
        $result = (new StreamText($model))
            ->prompt('What is the weather?')
            ->onChunk(function (array $chunk) use (&$onChunkTypes): void {
                $onChunkTypes[] = $chunk['type'] ?? '';
            })
            ->execute();

        $parts = iterator_to_array($result->getFullStream(), false);

        return [$parts, $onChunkTypes];
    }

    /**
     * One tool call, arguments streamed as two raw-JSON fragments.
     *
     * @return array<int, array<string, mixed>>
     */
    private function toolInputScript(): array
    {
        return [
            ['type' => 'tool-input-start', 'id' => 'call_1', 'toolName' => 'getWeather'],
            ['type' => 'tool-input-delta', 'id' => 'call_1', 'delta' => '{"city"'],
            ['type' => 'tool-input-delta', 'id' => 'call_1', 'delta' => ':"NYC"}'],
            ['type' => 'tool-input-end', 'id' => 'call_1'],
            [
                'type' => 'tool-call',
                'toolCallId' => 'call_1',
                'toolName' => 'getWeather',
                'input' => '{"city":"NYC"}',
            ],
            ['type' => 'finish', 'finishReason' => 'tool-calls', 'usage' => new LanguageModelUsage(10, 5)],
        ];
    }

    public function testForwardsToolInputPartsWithLanguageModelKeysIntact(): void
    {
        [$parts] = $this->driveStream($this->toolInputScript());
        $types = array_column($parts, 'type');

        // Relative ordering survives the loop: start … end … tool-call.
        $start = array_search('tool-input-start', $types, true);
        $end = array_search('tool-input-end', $types, true);
        $call = array_search('tool-call', $types, true);
        $this->assertNotFalse($start, 'tool-input-start should be forwarded');
        $this->assertNotFalse($end, 'tool-input-end should be forwarded');
        $this->assertNotFalse($call, 'tool-call should be forwarded');
        $this->assertLessThan($end, $start);
        $this->assertLessThan($call, $end);

        // tool-input-start keeps its language-model keys; the loop adds `step`
        // and must NOT rename `id` -> `toolCallId` (that is the serializer's job).
        $startChunk = $parts[$start];
        $this->assertSame('call_1', $startChunk['id']);
        $this->assertSame('getWeather', $startChunk['toolName']);
        $this->assertSame(0, $startChunk['step']);
        $this->assertArrayNotHasKey('toolCallId', $startChunk);

        // Deltas keep the raw `delta` text (not renamed to `inputTextDelta`).
        $deltas = array_values(array_filter($parts, fn($p) => $p['type'] === 'tool-input-delta'));
        $this->assertCount(2, $deltas);
        $this->assertSame('{"city"', $deltas[0]['delta']);
        $this->assertSame(':"NYC"}', $deltas[1]['delta']);
        $this->assertArrayNotHasKey('inputTextDelta', $deltas[0]);
        $this->assertSame(0, $deltas[0]['step']);

        // tool-input-end keeps its id and gains a step.
        $endChunk = $parts[$end];
        $this->assertSame('call_1', $endChunk['id']);
        $this->assertSame(0, $endChunk['step']);
    }

    public function testOnChunkFiresForStartAndDeltaButNotEnd(): void
    {
        [, $onChunkTypes] = $this->driveStream($this->toolInputScript());

        $this->assertContains('tool-input-start', $onChunkTypes);
        $this->assertContains('tool-call', $onChunkTypes); // pre-existing parity
        $this->assertSame(
            2,
            count(array_filter($onChunkTypes, fn($t) => $t === 'tool-input-delta')),
            'onChunk should fire once per tool-input-delta'
        );

        // tool-input-end is forwarded on the full stream (asserted elsewhere)
        // but deliberately NOT surfaced to onChunk — matches Vercel's gate.
        $this->assertNotContains('tool-input-end', $onChunkTypes);
    }

    public function testToolInputEndIsForwardedButDroppedFromOnChunk(): void
    {
        [$parts, $onChunkTypes] = $this->driveStream($this->toolInputScript());

        $this->assertContains('tool-input-end', array_column($parts, 'type'));
        $this->assertNotContains('tool-input-end', $onChunkTypes);
    }
}
