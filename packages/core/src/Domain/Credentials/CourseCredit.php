<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * A course's authored credit under one scheme.
 *
 * The params are the scheme's own and are versioned like every other provider
 * shape, which is what makes a second regime a registration rather than a
 * migration: the table owns what a regulator query groups by, the scheme owns
 * its own parameters.
 */
final class CourseCredit
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly int $courseId,
        public readonly string $scheme,
        public readonly array $params = [],
        public readonly int $paramsVersion = 1,
        public readonly ?int $createdBy = null,
        public readonly ?int $id = null,
    ) {
    }

    /**
     * The shared fact every scheme derives its own amount from. Null when the
     * author recorded an amount directly rather than the minutes behind it.
     */
    public function contactMinutes(): ?int
    {
        $minutes = $this->params['contact_minutes'] ?? null;

        return \is_int($minutes) && $minutes > 0 ? $minutes : null;
    }

    public function fieldOfStudy(): ?string
    {
        $field = $this->params['field_of_study'] ?? null;

        return \is_string($field) && $field !== '' ? $field : null;
    }

    public function awardingBodyId(): ?string
    {
        $body = $this->params['awarding_body_id'] ?? null;

        return \is_string($body) && $body !== '' ? $body : null;
    }

    /**
     * An amount the author stated outright, which is what a site records when
     * the credit was decided by a body rather than derived from minutes.
     */
    public function statedAmount(): ?string
    {
        $amount = $this->params['amount'] ?? null;

        if (\is_string($amount) && is_numeric($amount)) {
            return $amount;
        }

        return \is_int($amount) || \is_float($amount) ? number_format((float) $amount, 2, '.', '') : null;
    }
}
