<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Privacy\RetentionBasis;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * How long one organisation keeps what it controls, as a versioned stored shape.
 *
 * The derivation runs rather than being asserted. In an employer-buys-seats
 * arrangement the employer determines the purposes, so the employer is the
 * **controller** for its members' training records while the platform stays the
 * controller for the learner's own account. GDPR Article 28(10) makes a
 * processor that determines purposes and means a controller in respect of that
 * processing, and EDPB Guidelines 07/2020 reserve the **essential means** to the
 * controller: the type of personal data, whose data, the **duration** of the
 * processing, and the recipients. A single site-wide retention number therefore
 * cannot be right for two organisations, which is why this is a column and not a
 * setting.
 *
 * **The audited party does not set the audit log's retention.** ISO/IEC
 * 27001:2022 A.8.15 requires that a privileged user cannot interact with the log
 * of the system they control, so {@see $accessLogDays} may only **lengthen** the
 * site-wide floor. A shorter value is refused at save with a named error, and the
 * refused attempt is itself recorded, because an organisation owner trying to
 * shorten their own audit trail is exactly the event the log exists to hold.
 *
 * The version is mirrored into `organisations.retention_policy_version` rather
 * than being read out of the JSON by every reader, so the question "which rows
 * can this build understand" is answerable without decoding every row.
 */
final class RetentionPolicy
{
    /** The shape this build writes. */
    public const SHAPE = 1;

    /** The longest retention anybody may ask for: ten years, in days. */
    public const MAXIMUM_DAYS = 3650;

    /** @param array<string, mixed> $extra */
    private function __construct(
        public readonly ?int $accessLogDays,
        public readonly ?int $artefactDays,
        public readonly ?int $recordDays,
        public readonly string $basis,
        public readonly ?string $statuteRef,
        public readonly int $version,
        private readonly array $extra,
    ) {
    }

    /** The absence of a policy, which is what every organisation has until one is written. */
    public static function none(): self
    {
        return new self(null, null, null, RetentionBasis::NONE, null, self::SHAPE, []);
    }

    /**
     * A policy as an administrator submitted it, validated.
     *
     * Every duration is optional and a null means "the site's answer", which is
     * the only honest default: an organisation that has not decided has not
     * decided, and writing the site floor into its row would make a decision on
     * its behalf and then present it as the organisation's own.
     *
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        return new self(
            self::days($input, 'access_log_days'),
            self::days($input, 'artefact_days'),
            self::days($input, 'record_days'),
            RetentionBasis::of(\is_string($input['basis'] ?? null) ? $input['basis'] : null),
            self::text($input, 'statute_ref'),
            self::SHAPE,
            [],
        );
    }

    /**
     * A policy read back out of the column.
     *
     * A row with no version, or with a version this build does not know, is read
     * as **no policy** rather than as a policy whose fields happen to parse.
     * Guessing at an unknown shape is how a future field's absence becomes a
     * shorter retention than the organisation asked for, and retention read too
     * short deletes evidence.
     *
     * @param array<string, mixed>|null $stored
     */
    public static function fromStored(?array $stored, int $columnVersion): self
    {
        if ($stored === null || $stored === []) {
            return self::none();
        }

        $version = StoredShape::versionOf($stored);
        $version = $version === StoredShape::UNVERSIONED ? $columnVersion : $version;

        if ($version !== self::SHAPE) {
            return self::none();
        }

        $body = StoredShape::bodyOf($stored);

        return new self(
            self::days($body, 'access_log_days'),
            self::days($body, 'artefact_days'),
            self::days($body, 'record_days'),
            RetentionBasis::of(\is_string($body['basis'] ?? null) ? $body['basis'] : null),
            self::text($body, 'statute_ref'),
            $version,
            [],
        );
    }

    public function isEmpty(): bool
    {
        return $this->accessLogDays === null
            && $this->artefactDays === null
            && $this->recordDays === null
            && $this->statuteRef === null
            && $this->basis === RetentionBasis::NONE;
    }

    /**
     * The refusal the design names, thrown rather than warned.
     *
     * It is a method on the policy and not a check in the write route, so the
     * rule travels with the value: a second write path added later gets the
     * refusal for free instead of getting it wrong.
     */
    public function requireNotBelowFloor(int $siteFloorDays): void
    {
        if ($this->accessLogDays !== null && $this->accessLogDays < $siteFloorDays) {
            throw ValidationException::forField('access_log_days', sprintf(
                'The site keeps its administrator access log for %d days and an organisation may only lengthen that, '
                . 'never shorten it. The audited party does not set the audit log\'s retention.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $siteFloorDays,
            ));
        }
    }

    /**
     * The stored column value, enveloped.
     *
     * @return array<string, mixed>
     */
    public function toStored(): array
    {
        return StoredShape::wrap(array_filter(
            [
                'access_log_days' => $this->accessLogDays,
                'artefact_days' => $this->artefactDays,
                'record_days' => $this->recordDays,
                'basis' => $this->basis === RetentionBasis::NONE ? null : $this->basis,
                'statute_ref' => $this->statuteRef,
            ] + $this->extra,
            static fn (mixed $value): bool => $value !== null,
        ), self::SHAPE);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'access_log_days' => $this->accessLogDays,
            'artefact_days' => $this->artefactDays,
            'record_days' => $this->recordDays,
            'basis' => $this->basis,
            'statute_ref' => $this->statuteRef,
            'version' => $this->version,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function days(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!\is_int($value) && !(\is_string($value) && ctype_digit($value))) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, 'A retention period is a whole number of days.');
        }

        $days = (int) $value;

        if ($days < 1 || $days > self::MAXIMUM_DAYS) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, sprintf(
                'A retention period is between 1 and %d days.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::MAXIMUM_DAYS,
            ));
        }

        return $days;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function text(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);

        if (mb_strlen($trimmed) > 190) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, 'A statute reference is at most 190 characters.');
        }

        return $trimmed;
    }
}
