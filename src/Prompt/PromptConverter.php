<?php

declare(strict_types=1);

namespace BengalStudio\AI\Prompt;

use BengalStudio\AI\Types\Message;

/**
 * Convert user-facing prompts to language model prompts.
 *
 * Mirrors Vercel AI SDK's standardizePrompt and convertToLanguageModelPrompt.
 */
class PromptConverter
{
    /**
     * Standardize a prompt from user-facing format to internal format.
     *
     * @param string|null $prompt A simple text prompt.
     * @param string|null $system A system message.
     * @param array<Message>|null $messages A list of messages.
     * @return array<Message> The standardized prompt.
     */
    public static function standardize(
        ?string $prompt = null,
        ?string $system = null,
        ?array $messages = null,
    ): array {
        if ($prompt !== null && $messages !== null) {
            throw new \InvalidArgumentException(
                'You can either use `prompt` or `messages` but not both.'
            );
        }

        if ($prompt === null && $messages === null) {
            throw new \InvalidArgumentException(
                'You must provide either `prompt` or `messages`.'
            );
        }

        $result = [];

        if ($system !== null) {
            $result[] = Message::system($system);
        }

        if ($prompt !== null) {
            $result[] = Message::user($prompt);
        } elseif ($messages !== null) {
            foreach ($messages as $message) {
                $result[] = $message;
            }
        }

        return $result;
    }

    /**
     * Convert messages to an array format for the language model.
     *
     * @param array<Message> $messages
     * @return array
     */
    public static function toLanguageModelPrompt(array $messages): array
    {
        return array_map(fn(Message $m) => $m->toArray(), $messages);
    }
}
