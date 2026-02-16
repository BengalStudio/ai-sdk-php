<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Util;

use BengalStudio\AI\Util\IdGenerator;
use PHPUnit\Framework\TestCase;

class IdGeneratorTest extends TestCase
{
    public function testGenerateHasPrefix(): void
    {
        $gen = new IdGenerator(prefix: 'ai');
        $id = $gen->generate();

        $this->assertStringStartsWith('ai-', $id);
    }

    public function testGenerateCorrectLength(): void
    {
        $gen = new IdGenerator(prefix: 'test', size: 16);
        $id = $gen->generate();

        // 'test-' (5) + 16 hex chars = 21
        $this->assertSame(21, strlen($id));
    }

    public function testCreateIdStatic(): void
    {
        $id = IdGenerator::createId('msg', 12);

        $this->assertStringStartsWith('msg-', $id);
        $this->assertSame(16, strlen($id)); // 'msg-' (4) + 12 hex chars
    }

    public function testGenerateIsUnique(): void
    {
        $gen = new IdGenerator();
        $ids = [];

        for ($i = 0; $i < 100; $i++) {
            $ids[] = $gen->generate();
        }

        $this->assertCount(100, array_unique($ids));
    }

    public function testDefaultPrefixAndSize(): void
    {
        $id = IdGenerator::createId();

        $this->assertStringStartsWith('ai-', $id);
        $this->assertSame(27, strlen($id)); // 'ai-' (3) + 24 hex chars
    }
}
