<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

/** The settings workspace read model, including the versioned runtime configuration and resolved host facts. */
final class PlatformSettings
{
    /**
     * @param array<string, string> $issuer the stored record, empty when the host defaults answer
     * @param array{id: string, name: string, url: string, image: string} $resolvedIssuer
     */
    public function __construct(
        public readonly ?int $certificateTemplateId,
        public readonly ?string $certificateTemplateName,
        public readonly ?string $certificateTemplateRenderer,
        public readonly ?string $verificationBaseUrl,
        public readonly int $heartbeatIntervalSeconds,
        public readonly int $maxSecondsPerHeartbeat,
        public readonly ?string $expirySweepSchedule,
        public readonly ?\DateTimeImmutable $expirySweepLastRunAt,
        public readonly string $commerceSource,
        public readonly string $commerceLabel,
        public readonly bool $selfEnrolment,
        public readonly ?string $hostSettingsUrl,
        public readonly array $issuer = [],
        public readonly array $resolvedIssuer = ['id' => '', 'name' => '', 'url' => '', 'image' => ''],
        /**
         * The external shop sources available for course catalogue links.
         *
         * @var list<array{key: string, label: string, is_default: bool}>
         */
        public readonly array $commerceSources = [],
        /** The CMS account used for automated shop enrolments. */
        public readonly ?int $backgroundActorUserId = null,
        /** Whether that account can actually grant, so the screen says so before an order does. */
        public readonly bool $backgroundActorMayGrant = false,
        /** Their display name, so the screen shows a person rather than an id. */
        public readonly ?string $backgroundActorName = null,
        /** @var array{revision: int, values: array<string, mixed>}|null */
        public readonly ?array $configuration = null,
        public readonly bool $publishLearnerName = false,
    ) {
    }
}
