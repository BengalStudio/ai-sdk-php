<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tool;

use BengalStudio\AI\Util\IdGenerator;

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
     * Whether this call must wait for a human, and under which approval id.
     *
     * Consulted before {@see executeToolCall()}, never after: a tool that needs
     * approval must not run first and ask later.
     *
     * @param ToolCall $toolCall The tool call about to run.
     * @param array<string, Tool> $tools Available tools.
     * @return ToolApproval|null Null when the call may proceed.
     */
    public static function approvalFor(ToolCall $toolCall, array $tools): ?ToolApproval
    {
        $tool = $tools[$toolCall->toolName] ?? null;

        // An unknown tool, or one with nothing to run, is not something a human
        // can usefully approve — executeToolCall() declines it a moment later.
        if ($tool === null || $tool->execute === null) {
            return null;
        }

        $decision = $tool->resolveApproval($toolCall->input, [
            'toolCallId' => $toolCall->toolCallId,
        ]);

        if ($decision === false) {
            return null;
        }

        return new ToolApproval(
            id: is_string($decision) ? $decision : IdGenerator::createId('approval'),
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            input: $toolCall->input,
        );
    }

    /**
     * Execute a tool call against the provided tools.
     *
     * @param ToolCall $toolCall The tool call to execute.
     * @param array<string, Tool> $tools Available tools.
     * @param array|null $approval Decision that released this call, when it was
     *        gated: `['id' => string, 'approved' => true, 'reason' => ?string]`.
     *        Passed through to the tool so a consumer can redeem the approval it
     *        recorded when it asked for one.
     * @return ToolResult|null
     */
    public static function executeToolCall(ToolCall $toolCall, array $tools, ?array $approval = null): ?ToolResult
    {
        $tool = $tools[$toolCall->toolName] ?? null;

        if ($tool === null || $tool->execute === null) {
            return null;
        }

        $options = ['toolCallId' => $toolCall->toolCallId];
        if ($approval !== null) {
            $options['approval'] = $approval;
        }

        $output = $tool($toolCall->input, $options);

        return new ToolResult(
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            input: $toolCall->input,
            output: $output,
        );
    }
}
