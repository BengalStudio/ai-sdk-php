<?php

declare(strict_types=1);

namespace BengalStudio\AI\Types;

/**
 * Finish reasons for language model generation.
 */
enum FinishReason: string
{
    case Stop = 'stop';
    case Length = 'length';
    case ToolCalls = 'tool-calls';
    case ContentFilter = 'content-filter';
    case Error = 'error';
    case Other = 'other';
    case Unknown = 'unknown';
}
