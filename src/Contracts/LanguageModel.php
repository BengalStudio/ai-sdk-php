<?php

declare(strict_types=1);

namespace BengalStudio\AI\Contracts;

use BengalStudio\AI\Types\LanguageModelCallOptions;
use BengalStudio\AI\Types\LanguageModelGenerateResult;
use BengalStudio\AI\Types\LanguageModelStreamResult;

/**
 * Specification for a language model.
 *
 * Mirrors Vercel AI SDK's LanguageModelV3 interface.
 */
interface LanguageModel
{
    /**
     * The specification version this model implements.
     */
    public function specificationVersion(): string;

    /**
     * Provider name for logging purposes.
     */
    public function provider(): string;

    /**
     * Provider-specific model ID.
     */
    public function modelId(): string;

    /**
     * Generate a complete response for the given options.
     */
    public function doGenerate(LanguageModelCallOptions $options): LanguageModelGenerateResult;

    /**
     * Generate a streaming response for the given options.
     *
     * @return LanguageModelStreamResult
     */
    public function doStream(LanguageModelCallOptions $options): LanguageModelStreamResult;
}
