<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * The certificate template contract's own vocabulary, and the reader that
 * checks a request body against it.
 *
 * It exists because fifteen routes write three levels of one aggregate and
 * each level has the same three problems: a field may be absent, present and
 * null, or present and wrong, and the three mean different things. A partial
 * update that cannot tell "absent" from "null" either refuses to clear a
 * nullable column or clears it on every save, and both were found in the
 * surfaces this replaces.
 *
 * Errors accumulate rather than throwing at the first one, because an author
 * fixing a form one field per round trip is the failure mode a 422 with a
 * field map exists to prevent.
 *
 * The vocabularies are constants here rather than in each use case so the
 * palette, the validator and the layout service cannot drift: an alignment
 * the contract accepts and the layout service does not understand is an
 * element that silently draws in the wrong place.
 */
final class CertificateTemplateFields
{
    /** Largest client precondition whose claimed successor fits signed INT. */
    public const MAX_EXPECTED_REVISION = 2_147_483_646;

    public const STATE_DRAFT = 'draft';
    public const STATE_PUBLISHED = 'published';
    public const STATE_ARCHIVED = 'archived';

    /** @var list<string> */
    public const STATES = [self::STATE_DRAFT, self::STATE_PUBLISHED, self::STATE_ARCHIVED];

    public const FORMAT_HTML = 'html';
    public const FORMAT_ELEMENTS = 'elements';

    /** @var list<string> */
    public const FORMATS = [self::FORMAT_HTML, self::FORMAT_ELEMENTS];

    /** @var list<string> */
    public const ORIENTATIONS = ['portrait', 'landscape'];

    /**
     * The reference points the layout service implements. British spelling on
     * the wire, which is what the contract declares; the American constant
     * the first element rows were written under is still read, and is not
     * offered for new writes.
     *
     * @var list<string>
     */
    public const REFPOINTS = [
        'top_left',
        'top_centre',
        'top_right',
        'centre',
        'bottom_left',
        'bottom_centre',
        'bottom_right',
    ];

    /** @var list<string> */
    public const ALIGNMENTS = ['left', 'centre', 'right', 'justify'];

    /**
     * What the criteria lock freezes on first issuance. Moodle's badge lock
     * rule: once a credential has been awarded under a criteria set, that set
     * and its expiry are frozen and a change is a new revision, because
     * otherwise the same credential means different things to different
     * holders and every audit question becomes unanswerable.
     *
     * @var list<string>
     */
    public const LOCKED_FIELDS = ['expiry_mode', 'expiry_days', 'expiry_at'];

    /** The default paper: A4 landscape, in millimetres. */
    public const DEFAULT_WIDTH = 297;
    public const DEFAULT_HEIGHT = 210;

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        private readonly array $body,
    ) {
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->body);
    }

    public function raw(string $key): mixed
    {
        return $this->body[$key] ?? null;
    }

    public function reject(string $key, string $problem): void
    {
        $this->errors[$key] = $problem;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @throws ValidationException when any field was rejected
     */
    public function check(): void
    {
        if ($this->errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException($this->errors);
        }
    }

    /**
     * A required, non-empty string. Absent is an error, because "required" on
     * a create is not the same question as "present" on a patch.
     */
    public function requiredString(string $key, int $maxLength): string
    {
        $value = $this->body[$key] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            $this->reject($key, 'A value is required.');

            return '';
        }

        return $this->boundedString($key, $value, $maxLength);
    }

    /**
     * A string the caller may omit. Null is returned for "absent", which the
     * caller distinguishes from "present and null" with has().
     */
    public function optionalString(string $key, int $maxLength): ?string
    {
        if (!\array_key_exists($key, $this->body)) {
            return null;
        }

        $value = $this->body[$key];

        if (!\is_string($value) || trim($value) === '') {
            $this->reject($key, 'A value is required.');

            return null;
        }

        return $this->boundedString($key, $value, $maxLength);
    }

    /**
     * A string column that may be cleared. Returns null for both "absent" and
     * "present and null"; has() tells them apart.
     */
    public function nullableString(string $key, int $maxLength): ?string
    {
        if (!\array_key_exists($key, $this->body)) {
            return null;
        }

        $value = $this->body[$key];

        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            $this->reject($key, 'A string or null is required.');

            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $this->boundedString($key, $trimmed, $maxLength);
    }

    public function optionalInt(string $key, int $minimum, ?int $maximum = null): ?int
    {
        if (!\array_key_exists($key, $this->body)) {
            return null;
        }

        $value = $this->body[$key];

        if (!\is_int($value)) {
            $this->reject($key, 'A whole number is required.');

            return null;
        }

        if ($value < $minimum) {
            $this->reject($key, sprintf('May not be below %d.', $minimum));

            return null;
        }

        if ($maximum !== null && $value > $maximum) {
            $this->reject($key, sprintf('May not exceed %d.', $maximum));

            return null;
        }

        return $value;
    }

    /** Exact paper dimensions, matching the storage column's two decimal places. */
    public function optionalDimension(string $key): ?float
    {
        if (!$this->has($key)) {
            return null;
        }
        $value = $this->body[$key];

        if (
            (!\is_int($value) && !\is_float($value)) || !is_finite((float) $value)
            || $value < 10 || $value > 1000 || abs($value - round($value, 2)) > 0.0000001
        ) {
            $this->reject($key, 'Use 10 to 1000 millimetres, with at most two decimal places.');

            return null;
        }

        return (float) $value;
    }

    public function requiredInt(string $key, int $minimum, ?int $maximum = null): int
    {
        if (!\array_key_exists($key, $this->body)) {
            $this->reject($key, 'A value is required.');

            return $minimum;
        }

        return $this->optionalInt($key, $minimum, $maximum) ?? $minimum;
    }

    public function expectedRevision(): int
    {
        return $this->requiredInt('expected_revision', 1, self::MAX_EXPECTED_REVISION);
    }

    /**
     * Refuses a PATCH that names only its concurrency precondition (or only
     * fields this resource does not own).
     *
     * @param list<string> $mutableFields
     */
    public function requireMutation(array $mutableFields): void
    {
        foreach ($mutableFields as $field) {
            if ($this->has($field)) {
                return;
            }
        }

        $this->reject('body', 'At least one editable field is required.');
    }

    public function nullableInt(string $key, int $minimum): ?int
    {
        if (!\array_key_exists($key, $this->body) || $this->body[$key] === null) {
            return null;
        }

        return $this->optionalInt($key, $minimum);
    }

    public function optionalBool(string $key): ?bool
    {
        if (!\array_key_exists($key, $this->body)) {
            return null;
        }

        $value = $this->body[$key];

        if (!\is_bool($value)) {
            $this->reject($key, 'True or false is required.');

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function optionalEnum(string $key, array $allowed): ?string
    {
        $value = $this->optionalString($key, 100);

        if ($value === null) {
            return null;
        }

        if (!\in_array($value, $allowed, true)) {
            $this->reject($key, 'One of: ' . implode(', ', $allowed) . '.');

            return null;
        }

        return $value;
    }

    /**
     * A UTC datetime column. The port's own format, because a value that
     * crosses the boundary in any other shape is a value the adapters
     * disagree about.
     */
    public function nullableDateTime(string $key): ?\DateTimeImmutable
    {
        if (!\array_key_exists($key, $this->body) || $this->body[$key] === null) {
            return null;
        }

        $value = $this->body[$key];

        if (!\is_string($value) || trim($value) === '') {
            $this->reject($key, 'A date and time, or null, is required.');

            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            $this->reject($key, 'Not a date and time this contract can read.');

            return null;
        }
    }

    /**
     * A provider payload. It must be a JSON object and never a list, and it
     * may not carry the reserved version marker: StoredShape owns the
     * envelope, and a provider shipping a top-level "_v" of its own would be
     * shadowed by it.
     *
     * @return array<string, mixed>|null
     */
    public function payload(string $key): ?array
    {
        if (!\array_key_exists($key, $this->body) || $this->body[$key] === null) {
            return null;
        }

        $value = $this->body[$key];

        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->reject($key, 'An object is required.');

            return null;
        }

        /** @var array<string, mixed> $value */
        if (\array_key_exists('_v', $value)) {
            $this->reject($key, 'The reserved key "_v" is owned by the payload envelope.');

            return null;
        }

        return $value;
    }

    /**
     * The whole ordered list of ids a reorder replaces an order with.
     *
     * @return list<int>
     */
    public function idList(string $key): array
    {
        $value = $this->body[$key] ?? null;

        if (!\is_array($value) || !array_is_list($value)) {
            $this->reject($key, 'An array of ids is required.');

            return [];
        }

        $ids = [];

        foreach ($value as $id) {
            if (!\is_int($id) || $id < 1) {
                $this->reject($key, 'Every id is a positive whole number.');

                return [];
            }

            $ids[] = $id;
        }

        return $ids;
    }

    private function boundedString(string $key, string $value, int $maxLength): string
    {
        $trimmed = trim($value);

        if (mb_strlen($trimmed) > $maxLength) {
            $this->reject($key, sprintf('May not exceed %d characters.', $maxLength));

            return '';
        }

        return $trimmed;
    }
}
