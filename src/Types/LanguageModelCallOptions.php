<?php

declare(strict_types=1);

namespace BengalStudio\AI\Types;

/**
 * Options for language model calls.
 */
class LanguageModelCallOptions
{
    /**
     * @param array<Message> $prompt The prompt messages.
     * @param int|null $maxOutputTokens Maximum number of tokens to generate.
     * @param float|null $temperature Temperature setting (0-2).
     * @param float|null $topP Nucleus sampling threshold.
     * @param int|null $topK Top K sampling.
     * @param float|null $frequencyPenalty Frequency penalty (-2 to 2).
     * @param float|null $presencePenalty Presence penalty (-2 to 2).
     * @param array<string>|null $stopSequences Stop sequences.
     * @param array|null $responseFormat Response format configuration.
     * @param int|null $seed Random seed for deterministic results.
     * @param array|null $tools Available tools.
     * @param array|null $toolChoice Tool choice strategy.
     * @param array|null $providerOptions Provider-specific options.
     */
    public function __construct(
        public readonly array $prompt,
        public readonly ?int $maxOutputTokens = null,
        public readonly ?float $temperature = null,
        public readonly ?float $topP = null,
        public readonly ?int $topK = null,
        public readonly ?float $frequencyPenalty = null,
        public readonly ?float $presencePenalty = null,
        public readonly ?array $stopSequences = null,
        public readonly ?array $responseFormat = null,
        public readonly ?int $seed = null,
        public readonly ?array $tools = null,
        public readonly ?array $toolChoice = null,
        public readonly ?array $providerOptions = null,
    ) {}

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return array_filter([
            'prompt' => array_map(fn(Message $m) => $m->toArray(), $this->prompt),
            'maxOutputTokens' => $this->maxOutputTokens,
            'temperature' => $this->temperature,
            'topP' => $this->topP,
            'topK' => $this->topK,
            'frequencyPenalty' => $this->frequencyPenalty,
            'presencePenalty' => $this->presencePenalty,
            'stopSequences' => $this->stopSequences,
            'responseFormat' => $this->responseFormat,
            'seed' => $this->seed,
            'tools' => $this->tools,
            'toolChoice' => $this->toolChoice,
            'providerOptions' => $this->providerOptions,
        ], fn($v) => $v !== null);
    }
}
