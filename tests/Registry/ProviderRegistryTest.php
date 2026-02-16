<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Registry;

use BengalStudio\AI\Contracts\EmbeddingModel;
use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Contracts\Provider;
use BengalStudio\AI\Exceptions\NoSuchProviderException;
use BengalStudio\AI\Registry\ProviderRegistry;
use PHPUnit\Framework\TestCase;

class ProviderRegistryTest extends TestCase
{
    public function testRegisterAndResolveLanguageModel(): void
    {
        $mockModel = $this->createMock(LanguageModel::class);
        $mockProvider = $this->createMock(Provider::class);
        $mockProvider->method('languageModel')->with('gpt-4o')->willReturn($mockModel);

        $registry = new ProviderRegistry(['openai' => $mockProvider]);
        $model = $registry->languageModel('openai:gpt-4o');

        $this->assertSame($mockModel, $model);
    }

    public function testRegisterAndResolveEmbeddingModel(): void
    {
        $mockModel = $this->createMock(EmbeddingModel::class);
        $mockProvider = $this->createMock(Provider::class);
        $mockProvider->method('embeddingModel')->with('text-embedding-3-small')->willReturn($mockModel);

        $registry = new ProviderRegistry(['openai' => $mockProvider]);
        $model = $registry->embeddingModel('openai:text-embedding-3-small');

        $this->assertSame($mockModel, $model);
    }

    public function testUnknownProviderThrows(): void
    {
        $registry = new ProviderRegistry();

        $this->expectException(NoSuchProviderException::class);
        $this->expectExceptionMessage('unknown');

        $registry->languageModel('unknown:gpt-4o');
    }

    public function testRegisterFluently(): void
    {
        $mockModel = $this->createMock(LanguageModel::class);
        $mockProvider = $this->createMock(Provider::class);
        $mockProvider->method('languageModel')->willReturn($mockModel);

        $registry = new ProviderRegistry();
        $returned = $registry->register('test', $mockProvider);

        // Returns self for chaining
        $this->assertSame($registry, $returned);

        $model = $registry->languageModel('test:model');
        $this->assertSame($mockModel, $model);
    }

    public function testCustomSeparator(): void
    {
        $mockModel = $this->createMock(LanguageModel::class);
        $mockProvider = $this->createMock(Provider::class);
        $mockProvider->method('languageModel')->with('gpt-4o')->willReturn($mockModel);

        $registry = new ProviderRegistry(
            providers: ['openai' => $mockProvider],
            separator: '/',
        );

        $model = $registry->languageModel('openai/gpt-4o');
        $this->assertSame($mockModel, $model);
    }
}
