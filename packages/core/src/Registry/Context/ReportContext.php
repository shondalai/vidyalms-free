<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * What a report provider is handed.
 *
 * The method took a loose parameter array and a principal, so it had nowhere
 * to carry the visibility scope a group leader's report needs, and every
 * report that wanted one would have parsed it out of the parameter bag by a
 * convention nobody published. Passing a context object is the house rule and
 * it is also the only way the scope is enforced once rather than in every
 * report.
 *
 * It is corrected in the same pass as the certificate renderer, before any
 * third-party report exists, because both are in the core's own violation
 * list and one rebuild of the host packages is cheaper than two.
 */
final class ReportContext
{
    /**
     * @param array<string, mixed> $parameters
     */
    private function __construct(
        public readonly array $parameters,
        public readonly Principal $principal,
        /**
         * How far this caller may see. Null means the caller's own
         * authorization already bounded the run, which is the site manager
         * case; a scope narrower than the site is the group leader case and a
         * report that cannot honour it must say so rather than answer widely.
         */
        public readonly ?VisibilityScope $visibility,
        public readonly ?int $courseId,
        public readonly ?int $cohortId,
        public readonly ?\DateTimeImmutable $from,
        public readonly ?\DateTimeImmutable $until,
        public readonly string $locale,
        /**
         * The resolved organisation scope, carried rather than re-derived.
         *
         * Flattening it into the (type, id) pair beside it at the hand-off
         * would drop the resolved unit ids, the caller's role keys, the basis
         * and the digest, which is where the leak actually happens: a report
         * that only knows "organisation 7" cannot tell a site manager's run
         * from an organisation reporter's, and the two legitimately see
         * different rows.
         *
         * Defaulted, so no shipped caller changes and no report has to read
         * it. A report that does read it declares HonoursOrgScope, and an
         * organisation-scoped run refuses one that does not.
         */
        public readonly ?OrgScope $orgScope = null,
        /**
         * The IANA zone this run's date buckets are cut on.
         *
         * **It is part of the run's identity, not of its rendering.** A
         * period-bounded, euro-denominated, timezone-sensitive filing extract
         * gets any of the three wrong and produces a wrong filing silently, so
         * the zone is stamped onto report_runs at run start exactly as
         * notification_queue.recipient_timezone is stamped at enqueue, and the
         * artefact can be regenerated identically afterwards. It lives here and
         * not in the parameter bag because every commerce and filing report
         * declares only bare from and until dates in its parameterSchema(), and
         * a boundary that lives in a bag nobody validates is a boundary nobody
         * recorded.
         *
         * Defaulted, so no shipped caller changes.
         */
        public readonly string $timezone = 'UTC',
        /**
         * The keyset cursor a tabular report resumes from.
         *
         * It lives here so no provider invents its own. Offset paging over an
         * append-only table under live traffic duplicates and skips rows while
         * the export runs, which is invisible in the file it produces.
         */
        public readonly ?string $cursor = null,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function create(
        array $parameters,
        Principal $principal,
        ?VisibilityScope $visibility = null,
        ?int $courseId = null,
        ?int $cohortId = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $until = null,
        string $locale = 'en-GB',
        ?OrgScope $orgScope = null,
        string $timezone = 'UTC',
        ?string $cursor = null,
    ): self {
        return new self(
            $parameters,
            $principal,
            $visibility ?? ($orgScope === null ? null : VisibilityScope::forOrganisation($orgScope)),
            $courseId ?? self::intOf($parameters, 'course_id'),
            $cohortId ?? self::intOf($parameters, 'cohort_id'),
            $from,
            $until,
            $locale,
            $orgScope,
            $timezone === '' ? 'UTC' : $timezone,
            $cursor,
        );
    }

    public function int(string $key): ?int
    {
        return self::intOf($this->parameters, $key);
    }

    public function string(string $key): ?string
    {
        $value = $this->parameters[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Whether this run is bounded to one cohort, group or course, which is
     * the question a resolver asks before answering site-wide.
     */
    public function isScoped(): bool
    {
        return $this->visibility !== null && !$this->visibility->isSiteWide();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function intOf(array $parameters, string $key): ?int
    {
        $value = $parameters[$key] ?? null;

        return \is_numeric($value) ? (int) $value : null;
    }
}
