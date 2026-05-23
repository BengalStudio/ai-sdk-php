<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

/**
 * Smooths text and reasoning streaming output by buffering incoming deltas
 * and re-emitting them in smaller human-paced pieces, with an optional
 * inter-chunk delay.
 *
 * Mirrors the Vercel AI SDK's smoothStream transform. The transform is a
 * generator-to-generator wrapper applied to the full stream produced by
 * StreamText. Non-smoothable chunk types (tool calls, finish, etc.) pass
 * through unchanged; the buffer is flushed before every pass-through.
 */
final class SmoothStream
{
    private const DEFAULT_DELAY_MS = 10;

    private const WORD_REGEX = '/\S+\s+/m';
    private const LINE_REGEX = '/\n+/m';

    /**
     * Build a transform closure.
     *
     * @param array{
     *   delayInMs?: int|null,
     *   chunking?: 'word'|'line'|string|callable|\IntlBreakIterator,
     *   _internal?: array{ delay?: callable }
     * } $options
     *
     * @return \Closure(\Generator): \Generator
     */
    public static function create(array $options = []): \Closure
    {
        $delayInMs = array_key_exists('delayInMs', $options)
            ? $options['delayInMs']
            : self::DEFAULT_DELAY_MS;

        $chunking = $options['chunking'] ?? 'word';

        $delayFn = $options['_internal']['delay']
            ?? static function (?int $ms): void {
                if ($ms === null || $ms <= 0) {
                    return;
                }
                usleep($ms * 1000);
            };

        $detect = self::buildDetector($chunking);

        return static function (\Generator $stream) use ($delayInMs, $delayFn, $detect): \Generator {
            $buffer = '';
            $type = null;
            $last = [];

            $flush = static function () use (&$buffer, &$type, &$last): ?array {
                if ($buffer === '' || $type === null) {
                    return null;
                }
                $out = $last;
                $out['type'] = $type;
                $out['textDelta'] = $buffer;
                unset($out['text']);
                $buffer = '';
                return $out;
            };

            foreach ($stream as $chunk) {
                $chunkType = $chunk['type'] ?? null;
                $smoothable = $chunkType === 'text-delta' || $chunkType === 'reasoning';

                if (!$smoothable) {
                    if (($flushed = $flush()) !== null) {
                        yield $flushed;
                    }
                    yield $chunk;
                    continue;
                }

                $sameType = $chunkType === $type;
                $sameId = ($chunk['id'] ?? null) === ($last['id'] ?? null);
                if ((!$sameType || !$sameId) && $buffer !== '') {
                    if (($flushed = $flush()) !== null) {
                        yield $flushed;
                    }
                }

                $buffer .= $chunk['textDelta'] ?? $chunk['text'] ?? '';
                $type = $chunkType;
                $last = $chunk;

                while (($match = $detect($buffer)) !== null) {
                    if ($match === '') {
                        throw new \InvalidArgumentException(
                            'Chunking function must return a non-empty string.'
                        );
                    }
                    if (!str_starts_with($buffer, $match)) {
                        throw new \InvalidArgumentException(sprintf(
                            'Chunking function must return a match that is a prefix of the buffer. Received: "%s" expected to start with "%s"',
                            $match,
                            $buffer
                        ));
                    }
                    $emit = $last;
                    $emit['type'] = $type;
                    $emit['textDelta'] = $match;
                    unset($emit['text']);
                    yield $emit;
                    $buffer = substr($buffer, strlen($match));
                    $delayFn($delayInMs);
                }
            }

            if (($flushed = $flush()) !== null) {
                yield $flushed;
            }
        };
    }

    /**
     * @param mixed $chunking
     * @return callable(string): ?string
     */
    private static function buildDetector(mixed $chunking): callable
    {
        if ($chunking instanceof \IntlBreakIterator) {
            return static function (string $buffer) use ($chunking): ?string {
                if ($buffer === '') {
                    return null;
                }
                $chunking->setText($buffer);
                $start = $chunking->first();
                $end = $chunking->next();
                if ($end === \IntlBreakIterator::DONE || $end === $start) {
                    return null;
                }
                $segment = substr($buffer, $start, $end - $start);
                return $segment === '' ? null : $segment;
            };
        }

        if (!is_string($chunking) && is_callable($chunking)) {
            // Validation of the callable's return value happens in the main loop
            // so we can produce errors with the offending buffer in context.
            return static function (string $buffer) use ($chunking): ?string {
                $match = $chunking($buffer);
                if ($match === null) {
                    return null;
                }
                if (!is_string($match)) {
                    throw new \InvalidArgumentException(
                        'Chunking function must return a string or null.'
                    );
                }
                return $match;
            };
        }

        $regex = match (true) {
            $chunking === 'word' => self::WORD_REGEX,
            $chunking === 'line' => self::LINE_REGEX,
            is_string($chunking) && self::isValidRegex($chunking) => $chunking,
            default => throw new \InvalidArgumentException(
                'chunking must be "word", "line", a PCRE pattern string, an IntlBreakIterator, or a callable.'
            ),
        };

        return static function (string $buffer) use ($regex): ?string {
            if (preg_match($regex, $buffer, $m, PREG_OFFSET_CAPTURE) !== 1) {
                return null;
            }
            /** @var array{0: string, 1: int} $first */
            $first = $m[0];
            return substr($buffer, 0, $first[1]) . $first[0];
        };
    }

    private static function isValidRegex(string $pattern): bool
    {
        set_error_handler(static fn() => null);
        $valid = @preg_match($pattern, '') !== false;
        restore_error_handler();
        return $valid;
    }
}
