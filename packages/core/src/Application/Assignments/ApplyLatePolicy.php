<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assignments;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\LatePolicyContext;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LateOutcome;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LateOutcomeInput;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LatePolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The grade write's one question of the late policy registry.
 *
 * The registry had no call site at all, so the free `none` provider and every
 * paid one were classes nothing drove, which is the failure the edition rule
 * names one step in from an empty seam: a free provider is only a free
 * provider if a free code path can reach it. This is that path, and it is a
 * free one: `courses.late_policy_key` and `courses.late_policy_params` are
 * free columns precisely so a free self-paced course with a due date on an
 * assignment exercises the seam.
 *
 * **The rule is never written into the grading path.** Everything about what
 * a late submission costs lives behind the provider, and the caller supplies
 * only the raw percentage, the deadline and the arrival instant.
 *
 * The published miss is applied here rather than in the caller: a key whose
 * package has been removed falls back to `none` and the outcome records the
 * substitution, so the grade stays readable and says what produced it.
 */
final class ApplyLatePolicy
{
    public const DEFAULT_KEY = 'none';

    public const COLUMN_KEY = 'late_policy_key';
    public const COLUMN_PARAMS = 'late_policy_params';
    public const COLUMN_PARAMS_VERSION = 'late_policy_params_version';

    public function __construct(
        private readonly LatePolicyRegistry $policies,
        private readonly DatabasePort $database,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function forCourse(
        int $courseId,
        float $rawPercentage,
        ?\DateTimeImmutable $dueAt,
        \DateTimeImmutable $submittedAt,
    ): LateOutcome {
        $course = $this->database->selectOne(
            Query::from('courses')
                ->select(['id', self::COLUMN_KEY, self::COLUMN_PARAMS, self::COLUMN_PARAMS_VERSION, 'timezone'])
                ->where('id', '=', $courseId),
        );

        $stored = \is_string($course[self::COLUMN_KEY] ?? null) && $course[self::COLUMN_KEY] !== ''
            ? (string) $course[self::COLUMN_KEY]
            : self::DEFAULT_KEY;

        $key = $stored;
        $substituted = null;

        if (!$this->policies->has($key)) {
            $this->logger->notice('VidyaLMS late policy is not registered; falling back to none.', [
                'course' => $courseId,
                'policy' => $key,
            ]);
            $substituted = $key;
            $key = self::DEFAULT_KEY;
        }

        if (!$this->policies->has($key)) {
            // The free provider is always registered; if even it is gone the
            // grade is the raw one and says so, rather than throwing out of
            // the grading path.
            return new LateOutcome($rawPercentage, self::DEFAULT_KEY, [], $substituted);
        }

        $timezone = \is_string($course['timezone'] ?? null) && $course['timezone'] !== ''
            ? (string) $course['timezone']
            : 'UTC';

        $outcome = $this->policies->get($key)->apply(
            new LateOutcomeInput($rawPercentage, $dueAt, $submittedAt),
            LatePolicyContext::create(
                $this->paramsOf($course),
                $this->paramsVersionOf($course),
                $courseId,
                $timezone,
            ),
        );

        return $substituted === null ? $outcome : $outcome->substitutedFor($substituted);
    }

    /**
     * @param array<string, mixed>|null $course
     *
     * @return array<string, mixed>
     */
    private function paramsOf(?array $course): array
    {
        $raw = $course[self::COLUMN_PARAMS] ?? null;

        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return StoredShape::bodyOf($decoded);
    }

    /**
     * @param array<string, mixed>|null $course
     */
    private function paramsVersionOf(?array $course): int
    {
        $raw = $course[self::COLUMN_PARAMS] ?? null;

        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $bodyVersion = \is_array($decoded) ? StoredShape::versionOf($decoded) : 0;

            if ($bodyVersion > 0) {
                return $bodyVersion;
            }
        }

        $version = $course[self::COLUMN_PARAMS_VERSION] ?? null;

        return \is_numeric($version) && (int) $version > 0 ? (int) $version : 1;
    }
}
