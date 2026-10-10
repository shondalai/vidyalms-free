<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;

/**
 * What a condition leaf may read, deliberately small plus lazy rather than a
 * bag of everything a leaf might want.
 *
 * The required half carries the principal, the surface, the enrolment row
 * (null for a catalogue visitor), the course row (null on the notification
 * surface), the subject, now from ClockPort and nowhere else, the resolved
 * timezone chain and a batch hint saying how many subjects this evaluation
 * covers. Everything else arrives through facts(), populated in phase one
 * from the union of RequiresFacts::requiredFacts() across the leaves actually
 * present in the render set.
 *
 * That split does three jobs. A date leaf stops paying for a grade load on
 * every outline render, which is the difference between the query budget
 * holding and not. The cohort row and the membership row stop being members
 * of a free interface third parties implement, so one paid concept no longer
 * occupies two slots in a free signature while a third-party grouping
 * provider gets none. And a third-party leaf can contribute to the preload
 * rather than issuing a query per item.
 *
 * A declared fact key no loader answers is a save-time refusal rather than a
 * silent null, which is why facts() throws on an unloaded key rather than
 * returning an empty array.
 */
final class AvailabilityContext
{
    public const FACT_PROGRESS = 'progress';
    public const FACT_GRADES = 'grades';
    public const FACT_DATE_WINDOWS = 'date_windows';
    public const FACT_ENTITLEMENTS = 'entitlements';
    public const FACT_COHORT = 'cohort';
    public const FACT_COHORT_MEMBERSHIP = 'cohort_membership';

    /**
     * @param array<string, mixed>|null $enrolment
     * @param array<string, mixed>|null $course
     * @param list<string>              $timezoneChain cohort, then course, then site
     * @param array<string, mixed>      $facts
     */
    private function __construct(
        public readonly Principal $principal,
        public readonly AvailabilitySurface $surface,
        public readonly \DateTimeImmutable $now,
        public readonly ?array $enrolment,
        public readonly ?array $course,
        public readonly ?GateSubject $subject,
        public readonly array $timezoneChain,
        public readonly int $batchHint,
        private readonly array $facts,
    ) {
    }

    /**
     * @param array<string, mixed>|null $enrolment
     * @param array<string, mixed>|null $course
     * @param list<string>              $timezoneChain
     * @param array<string, mixed>      $facts
     */
    public static function create(
        Principal $principal,
        AvailabilitySurface $surface,
        \DateTimeImmutable $now,
        ?array $enrolment = null,
        ?array $course = null,
        ?GateSubject $subject = null,
        array $timezoneChain = ['UTC'],
        int $batchHint = 1,
        array $facts = [],
    ): self {
        return new self(
            $principal,
            $surface,
            $now,
            $enrolment,
            $course,
            $subject,
            $timezoneChain === [] ? ['UTC'] : $timezoneChain,
            max(1, $batchHint),
            $facts,
        );
    }

    /**
     * The same context pointed at another subject, which is what the batch
     * phase hands a provider once per item without reloading the facts.
     */
    public function forSubject(?GateSubject $subject): self
    {
        return new self(
            $this->principal,
            $this->surface,
            $this->now,
            $this->enrolment,
            $this->course,
            $subject,
            $this->timezoneChain,
            $this->batchHint,
            $this->facts,
        );
    }

    /**
     * @param array<string, mixed> $facts
     */
    public function withFacts(array $facts): self
    {
        return new self(
            $this->principal,
            $this->surface,
            $this->now,
            $this->enrolment,
            $this->course,
            $this->subject,
            $this->timezoneChain,
            $this->batchHint,
            $facts + $this->facts,
        );
    }

    public function withNow(\DateTimeImmutable $now): self
    {
        return new self(
            $this->principal,
            $this->surface,
            $now,
            $this->enrolment,
            $this->course,
            $this->subject,
            $this->timezoneChain,
            $this->batchHint,
            $this->facts,
        );
    }

    public function hasFact(string $key): bool
    {
        return \array_key_exists($key, $this->facts);
    }

    /**
     * A preloaded fact set. A key nothing loaded is a programmer error: the
     * leaf declared it through RequiresFacts and the composer preloads what
     * the leaves declare, so an absent key means the declaration was not
     * made.
     */
    public function facts(string $key): mixed
    {
        if (!\array_key_exists($key, $this->facts)) {
            throw new \LogicException(sprintf(
                'The fact set "%s" was not preloaded. Declare it through RequiresFacts::requiredFacts().',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $key,
            ));
        }

        return $this->facts[$key];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function factRows(string $key): array
    {
        $value = $this->facts($key);

        return \is_array($value) ? $value : [];
    }

    public function enrolmentId(): ?int
    {
        $id = $this->enrolment['id'] ?? null;

        return \is_numeric($id) ? (int) $id : null;
    }

    public function courseId(): ?int
    {
        $id = $this->course['id'] ?? null;

        return \is_numeric($id) ? (int) $id : null;
    }

    /**
     * The first zone of the chain: the cohort's if there is one, else the
     * course's, else the site's. An authored wall-clock input resolves
     * against this and never against the PHP process timezone.
     */
    public function timezone(): string
    {
        return $this->timezoneChain[0] ?? 'UTC';
    }

    public function siteTimezone(): string
    {
        $last = \count($this->timezoneChain) - 1;

        return $this->timezoneChain[$last] ?? 'UTC';
    }
}
