<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Types;

use BengalStudio\AI\Types\LanguageModelResponseMetadata;
use PHPUnit\Framework\TestCase;

class LanguageModelResponseMetadataTest extends TestCase
{
    public function testDefaults(): void
    {
        $meta = new LanguageModelResponseMetadata();

        $this->assertNull($meta->id);
        $this->assertNull($meta->timestamp);
        $this->assertNull($meta->modelId);
        $this->assertNull($meta->headers);
        $this->assertNull($meta->body);
    }

    public function testWithValues(): void
    {
        $ts = new \DateTimeImmutable('2026-01-15T12:00:00Z');
        $meta = new LanguageModelResponseMetadata(
            id: 'chatcmpl-123',
            timestamp: $ts,
            modelId: 'gpt-4o',
            headers: ['x-request-id' => 'abc'],
        );

        $this->assertSame('chatcmpl-123', $meta->id);
        $this->assertSame($ts, $meta->timestamp);
        $this->assertSame('gpt-4o', $meta->modelId);
    }

    public function testToArray(): void
    {
        $ts = new \DateTimeImmutable('2026-01-15T12:00:00+00:00');
        $meta = new LanguageModelResponseMetadata(
            id: 'chatcmpl-123',
            timestamp: $ts,
            modelId: 'gpt-4o',
        );

        $arr = $meta->toArray();
        $this->assertSame('chatcmpl-123', $arr['id']);
        $this->assertSame('gpt-4o', $arr['modelId']);
        $this->assertStringContains('2026', $arr['timestamp']);
    }

    public function testToArrayOmitsNulls(): void
    {
        $meta = new LanguageModelResponseMetadata(id: 'resp_1');
        $arr = $meta->toArray();

        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayNotHasKey('timestamp', $arr);
        $this->assertArrayNotHasKey('modelId', $arr);
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '$haystack' contains '$needle'."
        );
    }
}
