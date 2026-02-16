<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tool;

/**
 * Prepare tools and tool choice for a language model call.
 *
 * Converts user-facing tool definitions to the internal format
 * expected by language model implementations.
 */
class ToolPreparer
{
    /**
     * Prepare tools and tool choice for a model call.
     *
     * @param array<string, Tool>|null $tools Tool definitions keyed by name.
     * @param string|array|null $toolChoice Tool choice strategy.
     * @return array{tools: array|null, toolChoice: array|null}
     */
    public static function prepare(?array $tools, string|array|null $toolChoice = null): array
    {
        if ($tools === null || empty($tools)) {
            return ['tools' => null, 'toolChoice' => null];
        }

        $languageModelTools = [];

        foreach ($tools as $name => $tool) {
            $languageModelTools[] = $tool->toLanguageModelTool($name);
        }

        // Normalize tool choice
        $normalizedChoice = null;
        if ($toolChoice !== null) {
            if (is_string($toolChoice)) {
                $normalizedChoice = ['type' => $toolChoice];
            } else {
                $normalizedChoice = $toolChoice;
            }
        } elseif (!empty($languageModelTools)) {
            $normalizedChoice = ['type' => 'auto'];
        }

        return [
            'tools' => $languageModelTools,
            'toolChoice' => $normalizedChoice,
        ];
    }

    /**
     * Execute a tool call against the provided tools.
     *
     * @param ToolCall $toolCall The tool call to execute.
     * @param array<string, Tool> $tools Available tools.
     * @return ToolResult|null
     */
    public static function executeToolCall(ToolCall $toolCall, array $tools): ?ToolResult
    {
        $tool = $tools[$toolCall->toolName] ?? null;

        if ($tool === null || $tool->execute === null) {
            return null;
        }

        $output = $tool($toolCall->input, [
            'toolCallId' => $toolCall->toolCallId,
        ]);

        return new ToolResult(
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            input: $toolCall->input,
            output: $output,
        );
    }
}
