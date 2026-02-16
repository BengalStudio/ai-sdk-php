<?php

declare(strict_types=1);

namespace BengalStudio\AI\Contracts;

/**
 * Middleware for language models.
 *
 * Mirrors Vercel AI SDK's LanguageModelV3Middleware interface.
 */
interface LanguageModelMiddleware
{
    /**
     * Transform the call options before they are passed to the model.
     */
    public function transformParams(array $params, LanguageModel $model): array;

    /**
     * Wrap the doGenerate call.
     *
     * @param callable $doGenerate The original doGenerate function.
     * @param array $params The call options.
     * @param LanguageModel $model The language model.
     * @return mixed
     */
    public function wrapGenerate(callable $doGenerate, array $params, LanguageModel $model): mixed;

    /**
     * Wrap the doStream call.
     *
     * @param callable $doStream The original doStream function.
     * @param array $params The call options.
     * @param LanguageModel $model The language model.
     * @return mixed
     */
    public function wrapStream(callable $doStream, array $params, LanguageModel $model): mixed;
}
