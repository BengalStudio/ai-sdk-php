<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Core;

use BengalStudio\AI\Core\GenerateObjectResult;
use BengalStudio\AI\Types\FinishReason;
use BengalStudio\AI\Types\LanguageModelUsage;
use PHPUnit\Framework\TestCase;

class GenerateObjectResultTest extends TestCase
{
    public function testBasicConstruction(): void
    {
        $result = new GenerateObjectResult(
            object: ['name' => 'Alice', 'age' => 30],
            rawText: '{"name":"Alice","age":30}',
            finishReason: FinishReason::Stop,
            usage: new LanguageModelUsage(inputTokens: 50, outputTokens: 20),
        );

        $this->assertSame(['name' => 'Alice', 'age' => 30], $result->getObject());
        $this->assertSame('Alice', $result->object['name']);
        $this->assertSame(FinishReason::Stop, $result->finishReason);
    }

    public function testDotNotationGet(): void
    {
        $result = new GenerateObjectResult(
            object: [
                'user' => [
                    'name' => 'Bob',
                    'address' => ['city' => 'New York'],
                ],
                'items' => [
                    ['title' => 'First'],
                    ['title' => 'Second'],
                ],
            ],
            rawText: '{}',
            finishReason: FinishReason::Stop,
            usage: new LanguageModelUsage(),
        );

        $this->assertSame('Bob', $result->get('user.name'));
        $this->assertSame('New York', $result->get('user.address.city'));
        $this->assertSame('First', $result->get('items.0.title'));
    }

    public function testGetWithDefault(): void
    {
        $result = new GenerateObjectResult(
            object: ['key' => 'value'],
            rawText: '{}',
            finishReason: FinishReason::Stop,
            usage: new LanguageModelUsage(),
        );

        $this->assertSame('fallback', $result->get('nonexistent', 'fallback'));
        $this->assertNull($result->get('nonexistent'));
    }

    public function testToJson(): void
    {
        $result = new GenerateObjectResult(
            object: ['name' => 'Alice'],
            rawText: '{}',
            finishReason: FinishReason::Stop,
            usage: new LanguageModelUsage(),
        );

        $this->assertSame('{"name":"Alice"}', $result->toJson());
    }

    public function testToJsonPretty(): void
    {
        $result = new GenerateObjectResult(
            object: ['a' => 1],
            rawText: '{}',
            finishReason: FinishReason::Stop,
            usage: new LanguageModelUsage(),
        );

        $pretty = $result->toJson(JSON_PRETTY_PRINT);
        $this->assertStringContainsString("\n", $pretty);
    }
}
