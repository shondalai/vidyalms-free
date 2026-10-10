<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Port\Authorization\AuthorizationPort;
use Shondalai\VidyaLMS\Core\Port\Cache\CachePort;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Commerce\CommercePort;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPort;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\EmptyCourseCategories;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Events\EventBridgePort;
use Shondalai\VidyaLMS\Core\Port\Http\HttpPort;
use Shondalai\VidyaLMS\Core\Port\Http\RefusingHttp;
use Shondalai\VidyaLMS\Core\Port\Secrets\NoSecrets;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretsPort;
use Shondalai\VidyaLMS\Core\Port\I18n\FixedLocale;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentityPort;
use Shondalai\VidyaLMS\Core\Port\Identity\DomainVerificationPort;
use Shondalai\VidyaLMS\Core\Port\Identity\NoAssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\NoVerifiedAddresses;
use Shondalai\VidyaLMS\Core\Port\Identity\UnverifiedDomains;
use Shondalai\VidyaLMS\Core\Port\Identity\VerifiedAddressesPort;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Mail\MailerPort;
use Shondalai\VidyaLMS\Core\Port\Links\CourseLinkPort;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Port\Users\FixedRecipientProfile;
use Shondalai\VidyaLMS\Core\Port\Users\RecipientProfilePort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * The complete set of services an adapter must supply to boot the core.
 * Constructing this object is the whole integration surface of a CMS adapter.
 */
final class Ports
{
    public function __construct(
        public readonly DatabasePort $database,
        public readonly UsersPort $users,
        public readonly AuthorizationPort $authorization,
        public readonly StoragePort $storage,
        public readonly MailerPort $mailer,
        public readonly SchedulerPort $scheduler,
        public readonly EventBridgePort $eventBridge,
        public readonly CommercePort $commerce,
        public readonly TranslatorPort $translator,
        public readonly CachePort $cache,
        public readonly ClockPort $clock,
        public readonly RandomPort $random,
        public readonly LoggerInterface $logger,
        /**
         * The request locale.
         *
         * Optional and defaulted, because an adapter built before this port
         * existed must keep booting: adding a required member here would
         * turn a core upgrade into a fatal on every site whose adapter has
         * not been rebuilt, which is the same failure adding a method to
         * TranslatorPort produced on an installed paid package.
         */
        public readonly LocalePort $locale = new FixedLocale(),
        /**
         * The recipient's timezone and locale.
         *
         * Optional and defaulted for the same reason the locale port is: a
         * required new member turns a core upgrade into a fatal on every
         * adapter that has not been rebuilt. The default answers null to
         * both, which is honest rather than a guess, and the notification
         * engine then runs quiet hours in site time and renders in the site
         * locale, which the documentation says.
         */
        public readonly RecipientProfilePort $recipientProfile = new FixedRecipientProfile(),
        /** Native CMS categories available to the course authoring surface. */
        public readonly CourseCategoryPort $courseCategories = new EmptyCourseCategories(),
        public readonly ?CourseLinkPort $courseLinks = null,
        public readonly ?\Shondalai\VidyaLMS\Core\Port\Pdf\PdfPort $pdf = null,
        /**
         * The three identity seams, defaulted for the reason LocalePort gives
         * and seated together because the design calls them a coordinated
         * multi-package release if they arrive separately.
         *
         * Every one is a read and none of them writes anything. The core
         * stores no signing certificate, no client secret and no bearer token,
         * and the absence of the columns is what enforces that: the credential
         * stays with its holder, who is the auditable party.
         *
         * The defaults are three different honest answers rather than one. An
         * absent assertion is null, which is silence and never a denial, so it
         * removes no membership. An unverified domain is false, because a gate
         * nobody can answer answers no. An unknown address list is empty,
         * which means the host cannot say and never that the person has no
         * verified address. Reading any of the three as its opposite is the
         * bug each default is shaped to prevent.
         */
        public readonly AssertedIdentityPort $assertedIdentity = new NoAssertedIdentity(),
        public readonly DomainVerificationPort $domainVerification = new UnverifiedDomains(),
        public readonly VerifiedAddressesPort $verifiedAddresses = new NoVerifiedAddresses(),
        /** Outbound transport and credential resolution for optional integrations. */
        public readonly HttpPort $http = new RefusingHttp(),
        public readonly SecretsPort $secrets = new NoSecrets(),
    ) {
    }
}
