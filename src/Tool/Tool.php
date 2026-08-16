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
    /**
     * @param null|bool|\Closure $needsApproval Whether a call must be approved
     *        by a human before it runs. `null`/`false` executes normally;
     *        `true` requests approval under a generated id; a closure
     *        `fn(array $input, array $options): bool|string` decides per call,
     *        and may return a **string** to supply the approval id itself.
     */
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?array $inputSchema = null,
        public readonly ?\Closure $execute = null,
        public readonly ?string $type = null,
        public readonly ?array $providerOptions = null,
        public readonly null|bool|\Closure $needsApproval = null,
    ) {}

    /**
     * Define a tool.
     */
    public static function define(
        ?string $description = null,
        ?array $inputSchema = null,
        ?\Closure $execute = null,
        ?array $providerOptions = null,
        null|bool|\Closure $needsApproval = null,
    ): self {
        return new self(
            description: $description,
            inputSchema: $inputSchema,
            execute: $execute,
            type: 'function',
            providerOptions: $providerOptions,
            needsApproval: $needsApproval,
        );
    }

    /**
     * Whether this call needs a human decision, and under which id.
     *
     * Returns `false` to execute normally, `true` to request approval under an
     * id the caller generates, or a string to request approval under *that* id.
     *
     * The string form is a deliberate extension of the TypeScript SDK, where
     * the approval id is always generated internally. It exists so a consumer
     * can bind an approval to a record it already owns — a queue row, an audit
     * entry — instead of keeping a side table mapping one id to the other.
     *
     * @param array $input   The tool call input.
     * @param array $options Execution options (`toolCallId`).
     * @return bool|string
     */
    public function resolveApproval(array $input, array $options = []): bool|string
    {
        if ($this->needsApproval === null || $this->needsApproval === false) {
            return false;
        }

        if ($this->needsApproval === true) {
            return true;
        }

        $decision = ($this->needsApproval)($input, $options);

        // An empty string is a policy that meant to supply an id and failed to.
        // Reading it as "no approval needed" would turn a broken policy into an
        // unsupervised write, so it falls back to a generated id instead.
        if (is_string($decision)) {
            return $decision !== '' ? $decision : true;
        }

        return (bool) $decision;
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
