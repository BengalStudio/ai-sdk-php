<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tool;

/**
 * A tool call held back for a human decision.
 *
 * Produced by {@see ToolPreparer::approvalFor()} when a tool's `needsApproval`
 * says the call may not run unsupervised, and carried on the stream as a
 * `tool-approval-request` chunk. The run ends there; the decision arrives on a
 * later request as a `tool-approval-response` part.
 *
 * Everything needed to execute the call later is kept here, because by the time
 * the decision comes back the model turn that produced it is long finished.
 */
class ToolApproval
{
    public function __construct(
        public readonly string $id,
        public readonly string $toolCallId,
        public readonly string $toolName,
        public readonly mixed $input,
    ) {}

    /**
     * The full-stream chunk announcing that this call is waiting.
     *
     * Named for the AI SDK v6 UI chunk it becomes; the serializer passes the
     * two fields straight through.
     */
    public function toStreamChunk(): array
    {
        return [
            'type' => 'tool-approval-request',
            'approvalId' => $this->id,
            'toolCallId' => $this->toolCallId,
        ];
    }
}
