<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\AccessibilityProfile;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The declared personal needs and preferences of one learner.
 *
 * Read once, at StartAttempt, and frozen beside the policy snapshot, for the
 * same reason every other quiz policy is frozen: a profile changed mid-attempt
 * must not reshuffle the options of a question the learner is already looking
 * at.
 *
 * Stored under a settings name rather than in a table of its own, because the
 * profile is a small opaque document keyed by user and nothing queries across
 * learners. The read is a read-only lookup into the Platform context's table,
 * which the layering rules permit and an event does not fit.
 */
final class LearnerAccessibilityProfile
{
    public const SETTING_PREFIX = 'vidyalms.accessibility_profile.';

    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function of(int $userId): AccessibilityProfile
    {
        if ($userId <= 0) {
            return AccessibilityProfile::none();
        }

        $row = $this->database->selectOne(
            Query::from('settings')->select(['value'])->where('name', '=', self::SETTING_PREFIX . $userId),
        );

        $raw = $row['value'] ?? null;

        if (!\is_string($raw) || $raw === '') {
            return AccessibilityProfile::none();
        }

        $decoded = json_decode($raw, true);

        /** @var array<string, mixed>|null $elements */
        $elements = \is_array($decoded) ? $decoded : null;

        return AccessibilityProfile::fromArray($elements);
    }
}
