<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tool;

/**
 * Defines a tool that can be called by a language model.
 *
 * Mirrors Vercel AI SDK's tool() function.
 *
 * Usage:
 *   $weatherTool = Tool::define(
 *       description: 'Get the weather in a location',
 *       inputSchema: [
 *           'type' => 'object',
 *           'properties' => [
 *               'location' => ['type' => 'string', 'description' => 'The location'],
 *           ],
 *           'required' => ['location'],
 *       ],
 *       execute: function (array $input): array {
 *           return ['temperature' => 72, 'conditions' => 'sunny'];
 *       },
 *   );
 */
class Tool
{
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?array $inputSchema = null,
        public readonly ?\Closure $execute = null,
        public readonly ?string $type = null,
        public readonly ?array $providerOptions = null,
    ) {}

    /**
     * Define a tool.
     */
    public static function define(
        ?string $description = null,
        ?array $inputSchema = null,
        ?\Closure $execute = null,
        ?array $providerOptions = null,
    ): self {
        return new self(
            description: $description,
            inputSchema: $inputSchema,
            execute: $execute,
            type: 'function',
            providerOptions: $providerOptions,
        );
    }

    /**
     * Execute the tool with the given input.
     *
     * @param array $input The tool call input.
     * @param array $options Additional execution options.
     * @return mixed The tool result.
     */
    public function __invoke(array $input, array $options = []): mixed
    {
        if ($this->execute === null) {
            return null;
        }

        return ($this->execute)($input, $options);
    }

    /**
     * Convert to the language model tool format.
     */
    public function toLanguageModelTool(string $name): array
    {
        return array_filter([
            'type' => 'function',
            'name' => $name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'providerOptions' => $this->providerOptions,
        ], fn($v) => $v !== null);
    }
}
