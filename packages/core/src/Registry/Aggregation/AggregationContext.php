<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Aggregation;

/**
 * What an aggregation method may read while it reduces a learner's attempts
 * to one grade.
 *
 * The parameters are the provider's own, from
 * quizzes.attempt_aggregation_params: the n of an average of the best n, the
 * constant of a decaying average. They had nowhere to live before, which is
 * why "average of the best three" would otherwise have needed a migration
 * rather than a registration.
 */
final class AggregationContext
{
    /** @var array<string, mixed> */
    public readonly array $params;

    /**
     * @param array<string, mixed> $params
     */
    private function __construct(
        public readonly int $quizId,
        public readonly int $userId,
        public readonly int $passMark,
        array $params,
    ) {
        $this->params = $params;
    }

    /**
     * @param array<string, mixed>|null $params
     */
    public static function create(int $quizId, int $userId, int $passMark, ?array $params = null): self
    {
        return new self($quizId, $userId, $passMark, $params ?? []);
    }

    public function intParam(string $key, int $default): int
    {
        $value = $this->params[$key] ?? null;

        return \is_int($value) ? $value : $default;
    }

    public function floatParam(string $key, float $default): float
    {
        $value = $this->params[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : $default;
    }
}
