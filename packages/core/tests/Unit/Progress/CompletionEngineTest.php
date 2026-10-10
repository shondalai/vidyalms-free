<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Progress;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Progress\CompletionEngine;
use Shondalai\VidyaLMS\Core\Domain\Progress\CompletionInput;

final class CompletionEngineTest extends TestCase
{
    /**
     * @return array<string, array{0: CompletionInput, 1: bool}>
     */
    public static function decisionMatrix(): array
    {
        return [
            'all_lessons: empty course never completes' => [
                new CompletionInput(0, 0, CompletionEngine::RULE_ALL_LESSONS),
                false,
            ],
            'all_lessons: none completed' => [
                new CompletionInput(3, 0, CompletionEngine::RULE_ALL_LESSONS),
                false,
            ],
            'all_lessons: some completed' => [
                new CompletionInput(3, 2, CompletionEngine::RULE_ALL_LESSONS),
                false,
            ],
            'all_lessons: all completed' => [
                new CompletionInput(3, 3, CompletionEngine::RULE_ALL_LESSONS),
                true,
            ],
            'all_lessons: single lesson course' => [
                new CompletionInput(1, 1, CompletionEngine::RULE_ALL_LESSONS),
                true,
            ],
            'all_lessons: ignores threshold and final quiz' => [
                new CompletionInput(2, 1, CompletionEngine::RULE_ALL_LESSONS, 10, true),
                false,
            ],
            'percentage: null threshold never completes' => [
                new CompletionInput(4, 4, CompletionEngine::RULE_PERCENTAGE, null),
                false,
            ],
            'percentage: empty course never completes' => [
                new CompletionInput(0, 0, CompletionEngine::RULE_PERCENTAGE, 50),
                false,
            ],
            'percentage: below threshold' => [
                new CompletionInput(100, 49, CompletionEngine::RULE_PERCENTAGE, 50),
                false,
            ],
            'percentage: exactly at threshold' => [
                new CompletionInput(2, 1, CompletionEngine::RULE_PERCENTAGE, 50),
                true,
            ],
            'percentage: above threshold' => [
                new CompletionInput(4, 3, CompletionEngine::RULE_PERCENTAGE, 50),
                true,
            ],
            'percentage: threshold 100 requires everything' => [
                new CompletionInput(3, 2, CompletionEngine::RULE_PERCENTAGE, 100),
                false,
            ],
            'percentage: threshold 100 met' => [
                new CompletionInput(3, 3, CompletionEngine::RULE_PERCENTAGE, 100),
                true,
            ],
            'percentage: threshold 0 with any lessons' => [
                new CompletionInput(5, 0, CompletionEngine::RULE_PERCENTAGE, 0),
                true,
            ],
            'percentage: ignores final quiz result' => [
                new CompletionInput(10, 1, CompletionEngine::RULE_PERCENTAGE, 80, true),
                false,
            ],
            'pass_final: passed completes' => [
                new CompletionInput(0, 0, CompletionEngine::RULE_PASS_FINAL, null, true),
                true,
            ],
            'pass_final: failed final quiz' => [
                new CompletionInput(3, 3, CompletionEngine::RULE_PASS_FINAL, null, false),
                false,
            ],
            'pass_final: no final quiz yet' => [
                new CompletionInput(3, 3, CompletionEngine::RULE_PASS_FINAL, null, null),
                false,
            ],
            'pass_final: lessons alone are not enough' => [
                new CompletionInput(3, 3, CompletionEngine::RULE_PASS_FINAL, 100, null),
                false,
            ],
            'unknown rule never completes' => [
                new CompletionInput(3, 3, 'certified_mail', 100, true),
                false,
            ],
        ];
    }

    /**
     * @param CompletionInput $input
     */
    #[DataProvider('decisionMatrix')]
    public function testDecide(CompletionInput $input, bool $expected): void
    {
        self::assertSame($expected, (new CompletionEngine())->decide($input));
    }
}
