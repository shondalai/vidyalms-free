<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Access\ExpireEntitlements;
use Shondalai\VidyaLMS\Core\Application\Commerce\BackgroundActorResolver;
use Shondalai\VidyaLMS\Core\Application\Credentials\IssuerIdentity;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceRegistry;

/** Authorized settings reads and narrowly scoped writes. Runtime configuration is stored in VidyaLMS tables; CMS facts remain read-only. */
final class GetPlatformSettings
{
    /** The player's fixed cadence; the core's heartbeat cap is what it feeds. */
    public const HEARTBEAT_INTERVAL_SECONDS = 15;

    /** The most one heartbeat may credit, enforced by RecordLessonProgress. */
    public const MAX_SECONDS_PER_HEARTBEAT = 120;

    /**
     * The source a course that names none falls back to.
     *
     * The same key courses.commerce_source defaults to and GetCourseHandler
     * guards on, named once so the screen and the route cannot disagree about
     * which source is the default.
     */
    public const DEFAULT_COMMERCE_SOURCE = 'cms-shop';

    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CommerceSourceRegistry $commerceSources,
        private readonly Settings $settings,
        private readonly AccessGuard $guard,
        private readonly ?string $verificationBaseUrl = null,
        private readonly ?HostFact $expirySweepSchedule = null,
        private readonly ?HostFact $hostSettingsUrl = null,
        private readonly bool $selfEnrolment = true,
        /**
         * Who this site issues credentials as. Absent, the screen reports no
         * issuer and the write refuses, which is what a build with no
         * credential surface should do.
         */
        private readonly ?IssuerIdentity $issuer = null,
        /**
         * The names behind the background actor id, and the guard that says
         * whether it can actually grant. Absent, the screen reports the id
         * alone, which is what a build with no user directory has.
         */
        private readonly ?UsersPort $users = null,
        private readonly ?RuntimeConfiguration $configuration = null,
    ) {
    }

    public function execute(Principal $principal): PlatformSettings
    {
        $this->guard->require($principal, Capability::MANAGE);

        $template = $this->templates->findPublishedDefault();

        // The resolved default is the source a course that names none falls
        // back to, which is cms-shop where it is registered and otherwise the
        // first registration. Reporting only that one was what told a site
        // owner with two sources installed the wrong thing.
        $keys = $this->commerceSources->keys();
        $source = \in_array(self::DEFAULT_COMMERCE_SOURCE, $keys, true)
            ? self::DEFAULT_COMMERCE_SOURCE
            : ($keys[0] ?? '');

        $actor = $this->settings->getIntish(BackgroundActorResolver::SETTING);
        $sources = [];

        foreach ($keys as $key) {
            $sources[] = [
                'key' => $key,
                'label' => $this->commerceSources->get($key)->label(),
                'is_default' => $key === $source,
            ];
        }

        return new PlatformSettings(
            $template === null ? null : (int) $template['id'],
            $template === null ? null : (string) $template['name'],
            $template === null ? null : (string) $template['renderer'],
            $this->verificationBaseUrl === '' ? null : $this->verificationBaseUrl,
            self::HEARTBEAT_INTERVAL_SECONDS,
            self::MAX_SECONDS_PER_HEARTBEAT,
            $this->expirySweepSchedule?->value(),
            $this->lastSweepRun(),
            $source,
            $source === '' ? '' : $this->commerceSources->get($source)->label(),
            (bool) ($this->configuration?->get('self_enrolment', $this->selfEnrolment) ?? $this->selfEnrolment),
            $this->hostSettingsUrl?->value(),
            $this->issuer?->stored() ?? [],
            $this->issuer?->resolve() ?? ['id' => '', 'name' => '', 'url' => '', 'image' => ''],
            $sources,
            $actor > 0 ? $actor : null,
            $actor > 0 && $this->guard->can(Principal::user($actor), Capability::ENROL_OTHERS),
            $actor > 0 ? $this->users?->findById($actor)?->name : null,
            $this->configuration?->snapshot(),
            $this->settings->getBool(\Shondalai\VidyaLMS\Core\Application\Credentials\CredentialNamePublication::SITE_SETTING, false),
        );
    }

    /**
     * Names the account a webhook, a cron pass or a fan-out grants as.
     *
     * The second thing on this screen the API writes, and it is here for the
     * issuer's own reason: no host settings form has a field for it, the
     * resolver reads it on every gateway delivery, and while nothing could
     * write it a stock site deferred every delivery until the attempt ceiling
     * settled it failed. Null clears the row, which is the site owner saying
     * automated grants are not attributed to anybody rather than a value going
     * missing.
     *
     * The account is checked for {@see Capability::ENROL_OTHERS} at write
     * time. Saving an account that cannot grant would store a setting whose
     * only effect is a deferred order hours later, and the resolver would then
     * report a configuration gap on a setting somebody had made.
     */
    public function setBackgroundActor(Principal $principal, ?int $userId): PlatformSettings
    {
        $this->guard->require($principal, Capability::MANAGE);

        if ($userId === null) {
            $this->settings->set(BackgroundActorResolver::SETTING, null);

            return $this->execute($principal);
        }

        if ($userId < 1) {
            throw ValidationException::forField(
                'background_actor_user_id',
                'A user id is a positive whole number, or null for nobody.',
            );
        }

        if ($this->users !== null && !$this->users->exists($userId)) {
            throw ValidationException::forField('background_actor_user_id', 'No such account.');
        }

        if (!$this->guard->can(Principal::user($userId), Capability::ENROL_OTHERS)) {
            throw ValidationException::forField(
                'background_actor_user_id',
                'That account may not enrol other people, so a background grant made as them would be refused.',
            );
        }

        $this->settings->set(BackgroundActorResolver::SETTING, $userId);

        return $this->execute($principal);
    }

    /**
     * Names the site as the issuer of its own credentials.
     *
     * Requires manage, and writes exactly three declared fields. It is the
     * one thing on this screen the API writes, because there is no host
     * settings page that could: a CMS knows its site name and knows nothing
     * about who issues its credentials, and an unwritten record is what made
     * every shipped site emit an empty issuer id.
     *
     * @param array<string, mixed> $record
     */
    public function setIssuer(Principal $principal, array $record): PlatformSettings
    {
        $this->guard->require($principal, Capability::MANAGE);

        if ($this->issuer === null) {
            throw new \RuntimeException('This build has no issuer identity to write.');
        }

        $this->issuer->save($record);

        return $this->execute($principal);
    }

    public function setPublishLearnerName(Principal $principal, bool $publish): PlatformSettings
    {
        $this->guard->require($principal, Capability::MANAGE);
        $this->settings->set(\Shondalai\VidyaLMS\Core\Application\Credentials\CredentialNamePublication::SITE_SETTING, $publish);

        return $this->execute($principal);
    }

    /** @param array<string, mixed> $values */
    public function saveConfiguration(Principal $principal, int $revision, array $values): PlatformSettings
    {
        $this->guard->require($principal, Capability::MANAGE);
        if ($this->configuration === null) {
            throw new \RuntimeException('The runtime settings repository is unavailable.');
        }
        $this->configuration->save($revision, $values);
        return $this->execute($principal);
    }

    private function lastSweepRun(): ?\DateTimeImmutable
    {
        $stored = $this->settings->getString(ExpireEntitlements::LAST_RUN_SETTING);

        if ($stored === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stored, new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed;
    }
}
