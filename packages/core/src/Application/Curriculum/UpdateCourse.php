<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CompletionRule;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseVisibility;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseUpdated;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\Slug;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPort;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryState;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LatePolicyRegistry;

final class UpdateCourse
{
    private const ALLOWED_KEYS = [
        'title',
        'slug',
        'summary',
        'description',
        'image',
        'category_id',
        'level',
        'language',
        'visibility',
        'price_mode',
        'completion_rule',
        'completion_threshold',
        'featured',
        'enrolment_rule',
        'progression',
        'timezone',
        'starts_at',
        'late_policy_key',
        'late_policy_params',
    ];

    public function __construct(
        private readonly CourseRepository $courses,
        private readonly CourseAccess $access,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
        private readonly CourseCategoryPort $categories,
        private readonly EnrolmentRuleRegistry $enrolmentRules,
        private readonly LatePolicyRegistry $latePolicies,
    ) {
    }

    /**
     * @param array<string, mixed> $changes only the keys present are applied;
     *                                      state transitions go through
     *                                      PublishCourse and ArchiveCourse
     */
    public function execute(Principal $principal, int $courseId, array $changes): Course
    {
        $course = $this->access->require($principal, $courseId, Capability::TEACH);

        foreach (array_keys($changes) as $key) {
            if (!\in_array($key, self::ALLOWED_KEYS, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField((string) $key, 'This field cannot be updated.');
            }
        }

        $categoryId = $this->categoryChange($changes, $course->categoryId);
        $latePolicyKey = $this->latePolicyKeyChange($changes, $course->latePolicyKey);
        $latePolicyParams = $this->latePolicyParamsChange($changes, $course->latePolicyParams);
        $latePolicyParamsVersion = $this->latePolicyParamsVersion(
            $changes,
            $latePolicyKey,
            $course->latePolicyParamsVersion,
        );

        $updated = new Course(
            id: $course->id,
            title: $this->stringChange($changes, 'title', $course->title, 255),
            slug: $this->slugChange($changes, $course),
            summary: $this->nullableStringChange($changes, 'summary', $course->summary),
            description: $this->nullableStringChange($changes, 'description', $course->description),
            image: $this->nullableStringChange($changes, 'image', $course->image),
            categoryId: $categoryId,
            level: $this->nullableStringChange($changes, 'level', $course->level),
            language: $this->nullableStringChange($changes, 'language', $course->language),
            visibility: $this->visibilityChange($changes, $course->visibility),
            state: $course->state,
            priceMode: $this->stringChange($changes, 'price_mode', $course->priceMode, 20),
            completionRule: $this->completionRuleChange($changes, $course->completionRule),
            completionThreshold: $this->thresholdChange($changes, $course->completionThreshold),
            featured: $this->boolChange($changes, 'featured', $course->featured),
            createdBy: $course->createdBy,
            publishedAt: $course->publishedAt,
            enrolmentRule: $this->enrolmentRuleChange($changes, $course->enrolmentRule),
            progression: $this->progressionChange($changes, $course->progression),
            timezone: $this->timezoneChange($changes, $course->timezone),
            startsAt: $this->dateTimeChange($changes, 'starts_at', $course->startsAt),
            latePolicyKey: $latePolicyKey,
            latePolicyParams: $latePolicyParams,
            latePolicyParamsVersion: $latePolicyParamsVersion,
        );

        $this->courses->update($updated);

        $changedFields = $this->changedFields($course, $updated);

        // A PATCH that sets a field to what it already held is not an
        // occurrence: a listener that rewrites a permalink on every save
        // would otherwise do it every time an author pressed the button.
        if ($changedFields !== []) {
            $this->events->dispatch(new CourseUpdated(
                $this->clock->now(),
                $updated->id,
                $updated->title,
                $updated->slug->value,
                $updated->state->value,
                $changedFields,
                $principal->userId,
            ));
        }

        return $updated;
    }

    /**
     * @return list<string> the updatable fields whose value actually moved
     */
    private function changedFields(Course $before, Course $after): array
    {
        $values = static fn (Course $course): array => [
            'title' => $course->title,
            'slug' => $course->slug->value,
            'summary' => $course->summary,
            'description' => $course->description,
            'image' => $course->image,
            'category_id' => $course->categoryId,
            'level' => $course->level,
            'language' => $course->language,
            'visibility' => $course->visibility->value,
            'price_mode' => $course->priceMode,
            'completion_rule' => $course->completionRule->value,
            'completion_threshold' => $course->completionThreshold,
            'featured' => $course->featured,
            'enrolment_rule' => $course->enrolmentRule,
            'progression' => $course->progression,
            'timezone' => $course->timezone,
            'starts_at' => $course->startsAt?->format('Y-m-d H:i:s'),
            'late_policy_key' => $course->latePolicyKey,
            'late_policy_params' => $course->latePolicyParams,
        ];

        $old = $values($before);
        $new = $values($after);
        $changed = [];

        foreach (self::ALLOWED_KEYS as $key) {
            if ($old[$key] !== $new[$key]) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function stringChange(array $changes, string $key, string $current, int $maxLength): string
    {
        if (!\array_key_exists($key, $changes)) {
            return $current;
        }

        $value = $changes[$key];

        if (!\is_string($value) || trim($value) === '' || \strlen(trim($value)) > $maxLength) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, sprintf('A non-empty string of at most %d characters is required.', $maxLength));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function nullableStringChange(array $changes, string $key, ?string $current): ?string
    {
        if (!\array_key_exists($key, $changes)) {
            return $current;
        }

        $value = $changes[$key];

        if ($value !== null && !\is_string($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, 'A string or null is required.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function nullableIntChange(array $changes, string $key, ?int $current): ?int
    {
        if (!\array_key_exists($key, $changes)) {
            return $current;
        }

        $value = $changes[$key];

        if ($value !== null && (!\is_int($value) || $value < 1)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, 'A positive integer or null is required.');
        }

        return $value;
    }

    /**
     * Only a newly selected id is resolved. A stored id whose native row was
     * later deleted remains readable and can be cleared; a full settings
     * form may also send that unchanged id back without making the course
     * impossible to save.
     *
     * @param array<string, mixed> $changes
     */
    private function categoryChange(array $changes, ?int $current): ?int
    {
        $categoryId = $this->nullableIntChange($changes, 'category_id', $current);

        if (!\array_key_exists('category_id', $changes) || $categoryId === null || $categoryId === $current) {
            return $categoryId;
        }

        $category = $this->categories->findById($categoryId);

        if ($category === null) {
            throw ValidationException::forField('category_id', 'The course category was not found.');
        }

        if ($category->state === CourseCategoryState::Trashed) {
            throw ValidationException::forField('category_id', 'A trashed course category cannot be assigned.');
        }

        return $categoryId;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function enrolmentRuleChange(array $changes, string $current): string
    {
        if (!\array_key_exists('enrolment_rule', $changes)) {
            return $current;
        }

        $key = $this->providerKey($changes['enrolment_rule'], 'enrolment_rule');

        if (!$this->enrolmentRules->has($key)) {
            throw ValidationException::forField('enrolment_rule', 'The enrolment rule is not registered.');
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function progressionChange(array $changes, string $current): string
    {
        if (!\array_key_exists('progression', $changes)) {
            return $current;
        }

        $value = $changes['progression'];

        if (!\is_string($value) || !\in_array($value, ['linear', 'free'], true)) {
            throw ValidationException::forField('progression', 'The progression must be linear or free.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function timezoneChange(array $changes, ?string $current): ?string
    {
        if (!\array_key_exists('timezone', $changes)) {
            return $current;
        }

        $value = $changes['timezone'];

        if ($value === null) {
            return null;
        }

        if (!\is_string($value) || strlen($value) > 64 || !\in_array($value, timezone_identifiers_list(), true)) {
            throw ValidationException::forField('timezone', 'A valid IANA timezone of at most 64 characters is required.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function dateTimeChange(
        array $changes,
        string $key,
        ?\DateTimeImmutable $current,
    ): ?\DateTimeImmutable {
        if (!\array_key_exists($key, $changes)) {
            return $current;
        }

        $value = $changes[$key];

        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, 'A UTC datetime string or null is required.');
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, 'Expected a UTC datetime in "Y-m-d H:i:s" format.');
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function latePolicyKeyChange(array $changes, string $current): string
    {
        if (!\array_key_exists('late_policy_key', $changes)) {
            return $current;
        }

        $key = $this->providerKey($changes['late_policy_key'], 'late_policy_key');

        if (!$this->latePolicies->has($key)) {
            throw ValidationException::forField('late_policy_key', 'The late policy is not registered.');
        }

        return $key;
    }

    /**
     * @param array<string, mixed>      $changes
     * @param array<string, mixed>|null $current
     *
     * @return array<string, mixed>|null
     */
    private function latePolicyParamsChange(array $changes, ?array $current): ?array
    {
        if (!\array_key_exists('late_policy_params', $changes)) {
            return $current;
        }

        $value = $changes['late_policy_params'];

        if ($value === null) {
            return null;
        }

        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw ValidationException::forField('late_policy_params', 'An object or null is required.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function latePolicyParamsVersion(array $changes, string $key, int $current): int
    {
        if (!\array_key_exists('late_policy_key', $changes) && !\array_key_exists('late_policy_params', $changes)) {
            return $current;
        }

        if (!$this->latePolicies->has($key)) {
            throw ValidationException::forField('late_policy_key', 'The late policy is not registered.');
        }

        return $this->latePolicies->get($key)->currentShape();
    }

    private function providerKey(mixed $value, string $field): string
    {
        if (!\is_string($value) || trim($value) === '' || strlen(trim($value)) > 30) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'A non-empty provider key of at most 30 characters is required.');
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function boolChange(array $changes, string $key, bool $current): bool
    {
        if (!\array_key_exists($key, $changes)) {
            return $current;
        }

        $value = $changes[$key];

        if (\is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw ValidationException::forField($key, 'A boolean is required.');
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function slugChange(array $changes, Course $course): Slug
    {
        if (!\array_key_exists('slug', $changes)) {
            return $course->slug;
        }

        $value = $changes['slug'];

        if (!\is_string($value)) {
            throw ValidationException::forField('slug', 'A string is required.');
        }

        $candidate = Slug::fromString($value);

        if (!$candidate->equals($course->slug) && $this->courses->slugExists($candidate->value, $course->id)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ConflictException(sprintf('The slug "%s" is already in use by another course.', $candidate->value));
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function visibilityChange(array $changes, CourseVisibility $current): CourseVisibility
    {
        if (!\array_key_exists('visibility', $changes)) {
            return $current;
        }

        $value = $changes['visibility'];

        if ($value instanceof CourseVisibility) {
            return $value;
        }

        if (\is_string($value)) {
            $visibility = CourseVisibility::tryFrom($value);

            if ($visibility !== null) {
                return $visibility;
            }
        }

        throw ValidationException::forField('visibility', 'Visibility must be public, private or unlisted.');
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function completionRuleChange(array $changes, CompletionRule $current): CompletionRule
    {
        if (!\array_key_exists('completion_rule', $changes)) {
            return $current;
        }

        $value = $changes['completion_rule'];

        if ($value instanceof CompletionRule) {
            return $value;
        }

        if (\is_string($value)) {
            $rule = CompletionRule::tryFrom($value);

            if ($rule !== null) {
                return $rule;
            }
        }

        throw ValidationException::forField('completion_rule', 'The completion rule must be all_lessons, percentage or pass_final.');
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function thresholdChange(array $changes, ?int $current): ?int
    {
        if (!\array_key_exists('completion_threshold', $changes)) {
            return $current;
        }

        $value = $changes['completion_threshold'];

        if ($value !== null && (!\is_int($value) || $value < 1 || $value > 100)) {
            throw ValidationException::forField('completion_threshold', 'The completion threshold must be between 1 and 100.');
        }

        return $value;
    }
}
