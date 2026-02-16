<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Exceptions;

use BengalStudio\AI\Exceptions\AIException;
use BengalStudio\AI\Exceptions\APICallException;
use BengalStudio\AI\Exceptions\NoObjectGeneratedException;
use BengalStudio\AI\Exceptions\NoSuchModelException;
use BengalStudio\AI\Exceptions\NoSuchProviderException;
use BengalStudio\AI\Exceptions\RetryException;
use BengalStudio\AI\Exceptions\TooManyEmbeddingValuesException;
use PHPUnit\Framework\TestCase;

class ExceptionsTest extends TestCase
{
    public function testAIExceptionIsRuntimeException(): void
    {
        $e = new AIException('test');
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testAPICallException(): void
    {
        $e = new APICallException(
            message: 'Rate limited',
            statusCode: 429,
            responseBody: '{"error":{"message":"Rate limited"}}',
            url: 'https://api.openai.com/v1/chat/completions',
        );

        $this->assertSame(429, $e->statusCode);
        $this->assertSame('Rate limited', $e->getMessage());
        $this->assertNotNull($e->responseBody);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $e->url);
        $this->assertInstanceOf(AIException::class, $e);
    }

    public function testNoSuchModelException(): void
    {
        $e = new NoSuchModelException('gpt-5', 'languageModel');

        $this->assertSame('gpt-5', $e->modelId);
        $this->assertSame('languageModel', $e->modelType);
        $this->assertStringContainsString('gpt-5', $e->getMessage());
    }

    public function testNoSuchProviderException(): void
    {
        $e = new NoSuchProviderException('unknown-provider');

        $this->assertSame('unknown-provider', $e->providerId);
        $this->assertStringContainsString('unknown-provider', $e->getMessage());
    }

    public function testRetryException(): void
    {
        $errors = [new \RuntimeException('e1')];
        $e = new RetryException(maxRetries: 3, errors: $errors);

        $this->assertSame(3, $e->maxRetries);
        $this->assertCount(1, $e->errors);
        $this->assertStringContainsString('3', $e->getMessage());
    }

    public function testTooManyEmbeddingValuesException(): void
    {
        $e = new TooManyEmbeddingValuesException(
            provider: 'openai',
            modelId: 'text-embedding-3-small',
            maxEmbeddingsPerCall: 2048,
            providedCount: 3000,
        );

        $this->assertSame('openai', $e->provider);
        $this->assertSame(2048, $e->maxEmbeddingsPerCall);
        $this->assertSame(3000, $e->providedCount);
        $this->assertStringContainsString('2048', $e->getMessage());
        $this->assertStringContainsString('3000', $e->getMessage());
    }

    public function testNoObjectGeneratedException(): void
    {
        $e = new NoObjectGeneratedException(
            text: 'raw output',
            reason: 'Model refused',
        );

        $this->assertSame('raw output', $e->text);
        $this->assertSame('Model refused', $e->reason);
        $this->assertStringContainsString('Model refused', $e->getMessage());
    }

    public function testNoObjectGeneratedExceptionDefaults(): void
    {
        $e = new NoObjectGeneratedException();

        $this->assertSame('', $e->text);
        $this->assertNull($e->reason);
        $this->assertStringContainsString('No object generated', $e->getMessage());
    }
}
