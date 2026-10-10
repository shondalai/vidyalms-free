<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryContext;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryPolicyRegistry;

/**
 * The expiry setting a template write states, checked at save time against
 * the policy that will have to answer with it.
 *
 * The vocabulary is the registry's keys and never a literal list, which is
 * what makes a fourth expiry shape a registration: a paid policy that lapses
 * at the end of a reporting year is accepted here the day it registers, with
 * no core edit and no contract change.
 *
 * `isConfigured()` exists for exactly this moment. A policy that needs a day
 * count and has none says so while an author is looking at the form, rather
 * than answering null at issue, when nobody is watching and the absence is
 * frozen into a credential.
 *
 * The three fields are read as a merge over what the row already holds, so
 * changing only the day count is checked against the mode the template is
 * already in rather than against "never".
 */
final class CertificateExpiryRule
{
    public function __construct(
        private readonly ExpiryPolicyRegistry $policies,
        private readonly ClockPort $clock,
        private readonly string $siteTimezone = 'UTC',
    ) {
    }

    /**
     * The expiry column changes this body asks for, or an empty array when it
     * asks for none. Rejections land on the reader, so an author fixing a
     * form sees every problem at once.
     *
     * @param array<string, mixed> $current the template row, empty on a create
     *
     * @return array<string, mixed>
     */
    public function changesFor(CertificateTemplateFields $fields, array $current = []): array
    {
        $changes = [];
        $mode = \is_string($current['expiry_mode'] ?? null) && $current['expiry_mode'] !== ''
            ? $current['expiry_mode']
            : 'never';

        if ($fields->has('expiry_mode')) {
            $stated = $fields->optionalEnum('expiry_mode', $this->policies->keys());

            if ($stated === null) {
                return [];
            }

            $mode = $stated;
            $changes['expiry_mode'] = $stated;
        }

        $days = $fields->has('expiry_days')
            ? $fields->nullableInt('expiry_days', 1)
            : (is_numeric($current['expiry_days'] ?? null) ? (int) $current['expiry_days'] : null);

        if ($fields->has('expiry_days')) {
            $changes['expiry_days'] = $days;
        }

        $at = $fields->has('expiry_at')
            ? $fields->nullableDateTime('expiry_at')
            : self::moment($current['expiry_at'] ?? null);

        if ($fields->has('expiry_at')) {
            $changes['expiry_at'] = $at?->format('Y-m-d H:i:s');
        }

        if (!$fields->isValid() || $changes === []) {
            return $changes;
        }

        if (!$this->policies->has($mode)) {
            // Unreachable through optionalEnum, and stated anyway: the row
            // may already name a policy whose package has been removed, and a
            // save that only moves the day count must not be refused because
            // of it.
            return $changes;
        }

        $policy = $this->policies->get($mode);
        $context = ExpiryContext::create(
            $this->clock->now(),
            null,
            null,
            $days,
            $at,
            $this->siteTimezone,
            is_numeric($current['id'] ?? null) ? (int) $current['id'] : null,
        );

        if (!$policy->isConfigured($context)) {
            $fields->reject('expiry_mode', sprintf('The "%s" expiry policy needs more than this to answer.', $mode));
        }

        return $changes;
    }

    private static function moment(mixed $stored): ?\DateTimeImmutable
    {
        if (!\is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($stored, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
