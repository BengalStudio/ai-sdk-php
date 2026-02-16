<?php

declare(strict_types=1);

namespace BengalStudio\AI\Prompt;

/**
 * Prepare call settings, validating and normalizing them.
 */
class CallSettings
{
    public static function prepare(array $settings): array
    {
        $validated = [];

        if (isset($settings['maxOutputTokens'])) {
            $value = (int) $settings['maxOutputTokens'];
            if ($value < 1) {
                throw new \InvalidArgumentException('maxOutputTokens must be >= 1');
            }
            $validated['maxOutputTokens'] = $value;
        }

        if (isset($settings['temperature'])) {
            $value = (float) $settings['temperature'];
            if ($value < 0) {
                throw new \InvalidArgumentException('temperature must be >= 0');
            }
            $validated['temperature'] = $value;
        }

        if (isset($settings['topP'])) {
            $value = (float) $settings['topP'];
            if ($value < 0 || $value > 1) {
                throw new \InvalidArgumentException('topP must be between 0 and 1');
            }
            $validated['topP'] = $value;
        }

        if (isset($settings['topK'])) {
            $value = (int) $settings['topK'];
            if ($value < 1) {
                throw new \InvalidArgumentException('topK must be >= 1');
            }
            $validated['topK'] = $value;
        }

        if (isset($settings['frequencyPenalty'])) {
            $value = (float) $settings['frequencyPenalty'];
            if ($value < -2 || $value > 2) {
                throw new \InvalidArgumentException('frequencyPenalty must be between -2 and 2');
            }
            $validated['frequencyPenalty'] = $value;
        }

        if (isset($settings['presencePenalty'])) {
            $value = (float) $settings['presencePenalty'];
            if ($value < -2 || $value > 2) {
                throw new \InvalidArgumentException('presencePenalty must be between -2 and 2');
            }
            $validated['presencePenalty'] = $value;
        }

        if (isset($settings['stopSequences'])) {
            $validated['stopSequences'] = (array) $settings['stopSequences'];
        }

        if (isset($settings['seed'])) {
            $validated['seed'] = (int) $settings['seed'];
        }

        return $validated;
    }
}
