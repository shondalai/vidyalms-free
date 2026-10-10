<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\Presenter\AiPresenter;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The caller's own view of the data-use policy, and their acceptance of it.
 *
 * Level four of the four enablement levels. The version is the departure from
 * Moodle's `ai_policy_register`, which carries a user, a context and a time and
 * no version at all: without it a changed policy cannot force re-acceptance and
 * no audit row can say which policy was in force. Here the version is on the
 * acceptance row, is stamped onto every run, and a changed version re-prompts.
 *
 * The acceptance gate itself lives in the run pipeline rather than in each
 * placement, so a newly registered placement cannot forget it. This class is
 * the read and the write a person makes, and nothing more.
 */
final class AiPolicyCentre
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly SqlAiReadModel $reads,
        private readonly AiEnablementState $enablement,
        private readonly AiAccess $access,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * The active policy, this caller's acceptance state and the stop.
     *
     * `accepted` is true only against the active version: an acceptance of
     * version 3 while version 4 is active answers false and carries 3, which
     * is what makes a changed policy re-prompt rather than silently pass.
     *
     * @return array<string, mixed>
     */
    public function state(Principal $principal): array
    {
        $this->access->requireSelf($principal);
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $active = $this->reads->activePolicy($now);
        $acceptance = $principal->isGuest() ? null : $this->reads->latestAcceptance($principal->userId);
        $acceptedVersion = $acceptance === null ? null : (int) ($acceptance['policy_version'] ?? 0);

        return [
            'policy' => $active === null
                ? null
                : AiPresenter::policy($active, $this->reads->acceptanceCount((int) $active['version'])),
            'accepted' => $active !== null && $acceptedVersion === (int) $active['version'],
            'accepted_version' => $acceptedVersion,
            'accepted_at' => $acceptance === null ? null : (string) ($acceptance['accepted_at'] ?? ''),
            'stop' => $this->enablement->stop(),
            'site_enabled' => $this->enablement->siteEnabled(),
        ];
    }

    /**
     * Record this caller's acceptance of a named version.
     *
     * The version is named by the caller rather than implied, so an acceptance
     * recorded against a version that has since been superseded is a conflict
     * instead of a silent consent to text nobody read.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function accept(Principal $principal, array $body): array
    {
        $userId = $this->access->requireSelf($principal);
        $version = $body['version'] ?? null;

        if (!\is_int($version) && !(\is_string($version) && ctype_digit($version))) {
            throw ValidationException::forField('version', 'must be the policy version being accepted');
        }

        $version = (int) $version;
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $policy = $this->reads->policy($version) ?? throw EntityNotFoundException::for('AiPolicy', $version);

        if ((string) ($policy['state'] ?? '') !== 'active') {
            throw ConflictException::with(
                'That policy version is not the one in force, so accepting it would record consent to text nobody was shown.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                ['reason' => 'ai.policy.not_active', 'version' => $version],
            );
        }

        $scopeType = \is_string($body['scope_type'] ?? null) ? $body['scope_type'] : 'site';
        $scopeId = \is_int($body['scope_id'] ?? null) ? $body['scope_id'] : 0;
        $placementKey = \is_string($body['placement_key'] ?? null) && $body['placement_key'] !== ''
            ? $body['placement_key']
            : null;
        $evidence = \is_array($body['evidence'] ?? null) ? $body['evidence'] : null;
        if ($scopeType !== 'site' || $scopeId !== 0) {
            throw ValidationException::forField('scope_type', 'Accept the site policy in site scope.');
        }
        if (($placementKey !== null && strlen($placementKey) > 100)
            || ($evidence !== null && strlen(json_encode($evidence, \JSON_THROW_ON_ERROR)) > 4096)) {
            throw ValidationException::forField('evidence', 'The acceptance details are too large.');
        }
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->upsert(
            SqlAiReadModel::ACCEPTANCES,
            [
                'user_id' => $userId,
                'policy_version' => $version,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'placement_key' => $placementKey,
                'accepted_at' => $now,
                'evidence' => $evidence === null
                    ? null
                    : json_encode(StoredShape::wrap($evidence, 1), \JSON_THROW_ON_ERROR),
                'evidence_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['user_id', 'policy_version', 'scope_type', 'scope_id'],
            ['placement_key', 'accepted_at', 'evidence', 'evidence_version', 'updated_at'],
        );

        return $this->state($principal);
    }
}
