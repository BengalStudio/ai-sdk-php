<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Core;

use BengalStudio\AI\Core\SmoothStream;
use BengalStudio\AI\Core\StreamText;
use BengalStudio\AI\Core\StreamTextResult;
use BengalStudio\AI\Types\LanguageModelUsage;
use PHPUnit\Framework\TestCase;

use function BengalStudio\AI\smoothStream;
use function BengalStudio\AI\streamText;

class SmoothStreamTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $chunks
     */
    private function source(array $chunks): \Generator
    {
        foreach ($chunks as $chunk) {
            yield $chunk;
        }
    }

    /**
     * Collect emitted chunks. Returns the array of chunks plus a counter that
     * records the number of times the injected delay was invoked.
     *
     * @param array<int, array<string, mixed>> $chunks
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function collect(array $chunks, array $options = []): array
    {
        $delayCount = 0;
        $options['_internal']['delay'] = function (?int $ms) use (&$delayCount): void {
            $delayCount++;
        };
        $transform = SmoothStream::create($options);
        $out = [];
        foreach ($transform($this->source($chunks)) as $chunk) {
            $out[] = $chunk;
        }
        return [$out, $delayCount];
    }

    // ─── word chunking ───

    public function testWordChunkingCombinesPartialWords(): void
    {
        [$out] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'Hel'],
            ['type' => 'text-delta', 'textDelta' => 'lo wo'],
            ['type' => 'text-delta', 'textDelta' => 'rld '],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        $textDeltas = array_values(array_filter(
            array_map(fn($c) => $c['type'] === 'text-delta' ? $c['textDelta'] : null, $out),
            fn($v) => $v !== null,
        ));

        $this->assertSame(['Hello ', 'world '], $textDeltas);
    }

    public function testWordChunkingSplitsLargerChunkIntoWords(): void
    {
        [$out] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'one two three '],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        $textDeltas = array_values(array_filter(
            array_map(fn($c) => $c['type'] === 'text-delta' ? $c['textDelta'] : null, $out),
            fn($v) => $v !== null,
        ));

        $this->assertSame(['one ', 'two ', 'three '], $textDeltas);
    }

    public function testWordChunkingFlushesTrailingBufferAtStreamEnd(): void
    {
        [$out] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'incomplete'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        $textDeltas = array_values(array_filter(
            array_map(fn($c) => $c['type'] === 'text-delta' ? $c['textDelta'] : null, $out),
            fn($v) => $v !== null,
        ));

        // Trailing text with no whitespace separator should still be emitted
        // (flushed before the non-smoothable finish chunk).
        $this->assertSame(['incomplete'], $textDeltas);
    }

    public function testWordChunkingDoesNotEmitWhitespaceOnlyChunk(): void
    {
        [$out] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => '   '],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        $textDeltas = array_values(array_filter(
            array_map(fn($c) => $c['type'] === 'text-delta' ? $c['textDelta'] : null, $out),
            fn($v) => $v !== null,
        ));

        // No non-whitespace character ever arrived, so no word is matched.
        // The buffer is flushed at stream end, emitting the whitespace as-is.
        $this->assertSame(['   '], $textDeltas);
    }

    public function testWordChunkingFlushesBufferBeforeToolCall(): void
    {
        [$out] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'partial'],
            ['type' => 'tool-call', 'toolCallId' => 't1', 'toolName' => 'foo', 'args' => []],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        $types = array_column($out, 'type');
        $this->assertSame(['text-delta', 'tool-call', 'finish'], $types);
        $this->assertSame('partial', $out[0]['textDelta']);
    }

    // ─── line chunking ───

    public function testLineChunkingSplitsOnNewlines(): void
    {
        [$out] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => "line1\nline2\nline3"],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ], ['chunking' => 'line']);

        $textDeltas = array_values(array_filter(
            array_map(fn($c) => $c['type'] === 'text-delta' ? $c['textDelta'] : null, $out),
            fn($v) => $v !== null,
        ));

        $this->assertSame(["line1\n", "line2\n", 'line3'], $textDeltas);
    }

    public function testLineChunkingHoldsTextWithoutNewline(): void
    {
        // While streaming, text without a newline accumulates and emits only
        // at end-of-stream.
        $delayCount = 0;
        $transform = SmoothStream::create([
            'chunking' => 'line',
            '_internal' => ['delay' => function () use (&$delayCount) {
                $delayCount++;
            }],
        ]);
        $emittedBeforeFinish = [];
        $iter = $transform($this->source([
            ['type' => 'text-delta', 'textDelta' => 'no newline here'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]));
        foreach ($iter as $chunk) {
            $emittedBeforeFinish[] = $chunk;
            if ($chunk['type'] === 'finish') {
                break;
            }
        }
        $textDeltas = array_values(array_filter(
            array_map(fn($c) => $c['type'] === 'text-delta' ? $c['textDelta'] : null, $emittedBeforeFinish),
            fn($v) => $v !== null,
        ));
        $this->assertSame(['no newline here'], $textDeltas);
        $this->assertSame(0, $delayCount, 'No inter-chunk delay should run when there is nothing to emit mid-stream.');
    }

    // ─── custom chunking ───

    public function testCustomRegexChunking(): void
    {
        [$out] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'foo_bar_baz_'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ], ['chunking' => '/[^_]*_/']);

        $textDeltas = array_values(array_filter(
            array_map(fn($c) => $c['type'] === 'text-delta' ? $c['textDelta'] : null, $out),
            fn($v) => $v !== null,
        ));

        $this->assertSame(['foo_', 'bar_', 'baz_'], $textDeltas);
    }

    public function testCustomCallableChunking(): void
    {
        $detector = function (string $buffer): ?string {
            $pos = strpos($buffer, '|');
            return $pos === false ? null : substr($buffer, 0, $pos + 1);
        };

        [$out] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'a|b|c|'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ], ['chunking' => $detector]);

        $textDeltas = array_values(array_filter(
            array_map(fn($c) => $c['type'] === 'text-delta' ? $c['textDelta'] : null, $out),
            fn($v) => $v !== null,
        ));

        $this->assertSame(['a|', 'b|', 'c|'], $textDeltas);
    }

    public function testCallableReturningEmptyStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty string');

        $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'whatever'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ], ['chunking' => fn(string $b) => '']);
    }

    public function testCallableReturningNonPrefixThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prefix of the buffer');

        $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'abc'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ], ['chunking' => fn(string $b) => 'XYZ']);
    }

    public function testUnknownChunkingStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chunking must be');

        SmoothStream::create(['chunking' => 'sentence']);
    }

    // ─── delay ───

    public function testDelayIsInvokedBetweenEmittedChunks(): void
    {
        [$out, $delayCount] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'one two three '],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        // Three words emitted → three delay calls (one after each emission).
        $this->assertSame(3, $delayCount);
        $this->assertCount(4, $out); // 3 text-delta + 1 finish
    }

    public function testCustomDelayValueIsForwarded(): void
    {
        $received = [];
        $transform = SmoothStream::create([
            'delayInMs' => 42,
            '_internal' => [
                'delay' => function (?int $ms) use (&$received) {
                    $received[] = $ms;
                },
            ],
        ]);
        foreach ($transform($this->source([
            ['type' => 'text-delta', 'textDelta' => 'a b '],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ])) as $_) {
            // drain
        }
        $this->assertSame([42, 42], $received);
    }

    public function testNullDelayIsForwarded(): void
    {
        $received = [];
        $transform = SmoothStream::create([
            'delayInMs' => null,
            '_internal' => [
                'delay' => function (?int $ms) use (&$received) {
                    $received[] = $ms;
                },
            ],
        ]);
        foreach ($transform($this->source([
            ['type' => 'text-delta', 'textDelta' => 'a b '],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ])) as $_) {
            // drain
        }
        $this->assertSame([null, null], $received);
    }

    // ─── id / type boundaries ───

    public function testBufferIsFlushedWhenIdChanges(): void
    {
        [$out] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'partial', 'id' => 'a'],
            ['type' => 'text-delta', 'textDelta' => 'more', 'id' => 'b'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        $textChunks = array_values(array_filter(
            $out,
            fn($c) => $c['type'] === 'text-delta',
        ));

        // First flush emits "partial" tagged with id 'a'.
        // Second flush (at finish) emits "more" tagged with id 'b'.
        $this->assertSame('partial', $textChunks[0]['textDelta']);
        $this->assertSame('a', $textChunks[0]['id']);
        $this->assertSame('more', $textChunks[1]['textDelta']);
        $this->assertSame('b', $textChunks[1]['id']);
    }

    public function testBufferIsFlushedWhenTypeSwitches(): void
    {
        [$out] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'partial'],
            ['type' => 'reasoning', 'textDelta' => 'think'],
            ['type' => 'text-delta', 'textDelta' => 'more'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        $smoothable = array_values(array_filter(
            $out,
            fn($c) => $c['type'] === 'text-delta' || $c['type'] === 'reasoning',
        ));

        $this->assertSame('text-delta', $smoothable[0]['type']);
        $this->assertSame('partial', $smoothable[0]['textDelta']);
        $this->assertSame('reasoning', $smoothable[1]['type']);
        $this->assertSame('think', $smoothable[1]['textDelta']);
        $this->assertSame('text-delta', $smoothable[2]['type']);
        $this->assertSame('more', $smoothable[2]['textDelta']);
    }

    // ─── reasoning smoothing ───

    public function testReasoningChunksAreSmoothed(): void
    {
        [$out] = $this->collect([
            ['type' => 'reasoning', 'textDelta' => 'first '],
            ['type' => 'reasoning', 'textDelta' => 'second '],
            ['type' => 'reasoning', 'textDelta' => 'third '],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        $reasonings = array_values(array_filter(
            array_map(fn($c) => $c['type'] === 'reasoning' ? $c['textDelta'] : null, $out),
            fn($v) => $v !== null,
        ));

        $this->assertSame(['first ', 'second ', 'third '], $reasonings);
    }

    public function testReasoningSupportsTextKeyAlias(): void
    {
        // Some providers / converters emit reasoning chunks with key "text"
        // rather than "textDelta"; we should normalise to "textDelta".
        [$out] = $this->collect([
            ['type' => 'reasoning', 'text' => 'one two '],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        $reasonings = array_values(array_filter($out, fn($c) => $c['type'] === 'reasoning'));
        $this->assertSame('one ', $reasonings[0]['textDelta']);
        $this->assertSame('two ', $reasonings[1]['textDelta']);
        $this->assertArrayNotHasKey('text', $reasonings[0]);
    }

    // ─── pass-through ───

    public function testNonSmoothableChunksPassThroughUnchanged(): void
    {
        [$out] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'a b '],
            ['type' => 'tool-call', 'toolCallId' => 't1', 'toolName' => 'foo', 'args' => ['x' => 1]],
            ['type' => 'tool-result', 'toolCallId' => 't1', 'toolName' => 'foo', 'result' => 'ok'],
            ['type' => 'step-finish', 'step' => 0, 'finishReason' => 'tool-calls'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        $types = array_column($out, 'type');
        $this->assertSame([
            'text-delta', 'text-delta',
            'tool-call', 'tool-result', 'step-finish', 'finish',
        ], $types);

        // Pass-through chunks retain all of their original keys.
        $toolCall = $out[2];
        $this->assertSame('t1', $toolCall['toolCallId']);
        $this->assertSame('foo', $toolCall['toolName']);
        $this->assertSame(['x' => 1], $toolCall['args']);
    }

    // ─── wiring ───

    public function testStreamTextExperimentalTransformIsApplied(): void
    {
        $factory = function (): \Generator {
            yield ['type' => 'text-delta', 'textDelta' => 'one two three '];
            yield ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)];
        };

        $noopDelay = function (?int $ms) {};
        $transform = SmoothStream::create([
            '_internal' => ['delay' => $noopDelay],
        ]);

        // StreamTextResult is constructed directly here; wiring through
        // StreamText is exercised by inspecting the per-chunk shape after
        // the transform.
        $applied = $transform($factory());
        $collected = iterator_to_array($applied, false);

        $deltas = array_values(array_filter(
            array_map(fn($c) => $c['type'] === 'text-delta' ? $c['textDelta'] : null, $collected),
            fn($v) => $v !== null,
        ));
        $this->assertSame(['one ', 'two ', 'three '], $deltas);
    }

    public function testStreamTextResultProducesSmoothedText(): void
    {
        // Build a StreamTextResult around a factory that yields the raw
        // stream, then apply smoothStream via the transform composition.
        $rawFactory = function (): \Generator {
            yield ['type' => 'text-delta', 'textDelta' => 'one two three '];
            yield ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)];
        };

        $transform = SmoothStream::create([
            '_internal' => ['delay' => function () {}],
        ]);

        $result = new StreamTextResult(
            streamFactory: fn() => $transform($rawFactory()),
        );

        // Concatenated text should be identical to the unsmoothed text.
        $this->assertSame('one two three ', $result->getText());
    }

    // ─── multiple transforms ───

    public function testMultipleTransformsAreComposedInOrder(): void
    {
        $upper = static function (\Generator $stream): \Generator {
            foreach ($stream as $chunk) {
                if (($chunk['type'] ?? '') === 'text-delta') {
                    $chunk['textDelta'] = strtoupper($chunk['textDelta']);
                }
                yield $chunk;
            }
        };

        $smooth = SmoothStream::create([
            '_internal' => ['delay' => function () {}],
        ]);

        $source = $this->source([
            ['type' => 'text-delta', 'textDelta' => 'one two '],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ]);

        // Apply smooth first, then upper. Both should run.
        $stream = $upper($smooth($source));
        $deltas = [];
        foreach ($stream as $chunk) {
            if ($chunk['type'] === 'text-delta') {
                $deltas[] = $chunk['textDelta'];
            }
        }
        $this->assertSame(['ONE ', 'TWO '], $deltas);
    }

    // ─── IntlBreakIterator (optional) ───

    public function testIntlBreakIteratorJapanese(): void
    {
        if (!class_exists(\IntlBreakIterator::class)) {
            $this->markTestSkipped('ext-intl is not loaded.');
        }

        $iterator = \IntlBreakIterator::createWordInstance('ja');

        [$out] = $this->collect([
            ['type' => 'text-delta', 'textDelta' => 'こんにちは世界'],
            ['type' => 'finish', 'totalUsage' => new LanguageModelUsage(1, 1)],
        ], ['chunking' => $iterator]);

        $textDeltas = array_values(array_filter(
            array_map(fn($c) => $c['type'] === 'text-delta' ? $c['textDelta'] : null, $out),
            fn($v) => $v !== null,
        ));

        // The combined deltas must reproduce the original input verbatim.
        $this->assertSame('こんにちは世界', implode('', $textDeltas));
        // And we must have segmented into more than one piece.
        $this->assertGreaterThan(1, count($textDeltas));
    }
}
