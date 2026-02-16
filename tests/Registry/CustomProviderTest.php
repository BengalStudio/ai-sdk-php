<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Registry;

use BengalStudio\AI\Contracts\EmbeddingModel;
use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Contracts\Provider;
use BengalStudio\AI\Exceptions\NoSuchModelException;
use BengalStudio\AI\Registry\CustomProvider;
use PHPUnit\Framework\TestCase;

class CustomProviderTest extends TestCase
{
    public function testDirectLanguageModel(): void
    {
        $mockModel = $this->createMock(LanguageModel::class);
        $provider = new CustomProvider(
            languageModels: ['my-model' => $mockModel],
        );

        $this->assertSame($mockModel, $provider->languageModel('my-model'));
    }

    public function testDirectEmbeddingModel(): void
    {
        $mockModel = $this->createMock(EmbeddingModel::class);
        $provider = new CustomProvider(
            embeddingModels: ['my-emb' => $mockModel],
        );

        $this->assertSame($mockModel, $provider->embeddingModel('my-emb'));
    }

    public function testFallbackProvider(): void
    {
        $mockModel = $this->createMock(LanguageModel::class);
        $fallback = $this->createMock(Provider::class);
        $fallback->method('languageModel')->with('fallback-model')->willReturn($mockModel);

        $provider = new CustomProvider(
            languageModels: [],
            fallbackProvider: $fallback,
        );

        $this->assertSame($mockModel, $provider->languageModel('fallback-model'));
    }

    public function testMissingModelThrowsWithoutFallback(): void
    {
        $provider = new CustomProvider();

        $this->expectException(NoSuchModelException::class);
        $provider->languageModel('nonexistent');
    }

    public function testMissingEmbeddingModelThrowsWithoutFallback(): void
    {
        $provider = new CustomProvider();

        $this->expectException(NoSuchModelException::class);
        $provider->embeddingModel('nonexistent');
    }

    public function testDirectModelOverridesFallback(): void
    {
        $directModel = $this->createMock(LanguageModel::class);
        $fallbackModel = $this->createMock(LanguageModel::class);

        $fallback = $this->createMock(Provider::class);
        $fallback->method('languageModel')->willReturn($fallbackModel);

        $provider = new CustomProvider(
            languageModels: ['my-model' => $directModel],
            fallbackProvider: $fallback,
        );

        // Direct model wins over fallback
        $this->assertSame($directModel, $provider->languageModel('my-model'));
    }
}
