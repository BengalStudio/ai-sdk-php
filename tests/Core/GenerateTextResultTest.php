<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Core;

use BengalStudio\AI\Core\GenerateTextResult;
use BengalStudio\AI\Core\StepResult;
use BengalStudio\AI\Types\FinishReason;
use BengalStudio\AI\Types\LanguageModelResponseMetadata;
use BengalStudio\AI\Types\LanguageModelUsage;
use PHPUnit\Framework\TestCase;

class GenerateTextResultTest extends TestCase
{
    private function makeStep(string $text, FinishReason $reason, int $input = 10, int $output = 5): StepResult
    {
        return new StepResult(
            text: $text,
            toolCalls: [],
            toolResults: [],
            finishReason: $reason,
            usage: new LanguageModelUsage(inputTokens: $input, outputTokens: $output),
            warnings: [],
            response: new LanguageModelResponseMetadata(id: 'resp_1', modelId: 'gpt-4o'),
        );
    }

    public function testSingleStep(): void
    {
        $step = $this->makeStep('Hello!', FinishReason::Stop);
        $totalUsage = new LanguageModelUsage(inputTokens: 10, outputTokens: 5);

        $result = new GenerateTextResult(steps: [$step], totalUsage: $totalUsage);

        $this->assertSame('Hello!', $result->text);
        $this->assertSame('Hello!', $result->getText());
        $this->assertSame(FinishReason::Stop, $result->finishReason);
        $this->assertSame(10, $result->usage->inputTokens);
        $this->assertFalse($result->hasToolCalls());
        $this->assertSame(1, $result->getStepCount());
    }

    public function testMultipleSteps(): void
    {
        $step1 = $this->makeStep('Step 1', FinishReason::ToolCalls, 10, 5);
        $step2 = $this->makeStep('Step 2', FinishReason::Stop, 20, 10);
        $totalUsage = new LanguageModelUsage(inputTokens: 30, outputTokens: 15);

        $result = new GenerateTextResult(steps: [$step1, $step2], totalUsage: $totalUsage);

        // Last step text/finishReason
        $this->assertSame('Step 2', $result->text);
        $this->assertSame(FinishReason::Stop, $result->finishReason);
        $this->assertSame(2, $result->getStepCount());
        $this->assertSame(30, $result->usage->inputTokens);
    }

    public function testEmptySteps(): void
    {
        $result = new GenerateTextResult(
            steps: [],
            totalUsage: new LanguageModelUsage(),
        );

        $this->assertSame('', $result->text);
        $this->assertSame(FinishReason::Unknown, $result->finishReason);
        $this->assertSame(0, $result->getStepCount());
    }

    public function testResponseMetadata(): void
    {
        $step = $this->makeStep('test', FinishReason::Stop);
        $result = new GenerateTextResult(
            steps: [$step],
            totalUsage: new LanguageModelUsage(inputTokens: 10, outputTokens: 5),
        );

        $this->assertNotNull($result->response);
        $this->assertSame('resp_1', $result->response->id);
        $this->assertSame('gpt-4o', $result->response->modelId);
    }
}
