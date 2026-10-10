<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Event\GenericEvent;
use Joomla\CMS\Factory;
use Joomla\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Application\WebApplication;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\DI\Container;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Boot\KernelReady;
use Shondalai\VidyaLMS\Core\Boot\PortsReady;
use Shondalai\VidyaLMS\Core\Port\Authorization\AuthorizationPort;
use Shondalai\VidyaLMS\Core\Port\Cache\CachePort;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Commerce\CommercePort;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Events\EventBridgePort;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Mail\MailerPort;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaRecipientProfile;
use VidyaLMS\Component\VidyaLMS\Administrator\Provider\SiteSelfEnrolmentRule;

/**
 * The single entry point into the learning engine from Joomla.
 *
 * Controllers, plugins, modules and CLI commands call KernelFactory::kernel()
 * (or resolve CoreKernel::class from the global container, which is the same
 * shared instance) and then use the kernel's use cases. Nothing outside this
 * class constructs a CoreKernel, and nothing anywhere re-implements a rule the
 * kernel already owns.
 *
 * Building the kernel is also the platform's only extension seam: create()
 * installs the adapter's site policies onto the registries and then dispatches
 * KERNEL_READY_EVENT so plugins and Pro packages can do the same.
 *
 * @since 1.0.0
 */
final class KernelFactory
{
    public const COMPONENT = 'com_vidyalms';

    /**
     * What an outbound call identifies itself as.
     *
     * A constant rather than the installed version, because a gateway's abuse
     * desk needs to recognise the product, and a string that moves with every
     * release is one more thing that stops matching a rule somebody wrote.
     */
    public const OUTBOUND_USER_AGENT = 'VidyaLMS';

    /**
     * PublicLink substitutes the code into this query URL. It works with
     * Joomla SEF routing both enabled and disabled, including CLI issuance.
     */
    public const VERIFICATION_ROUTE = 'index.php?option=com_vidyalms&view=verify&code={value}';

    /**
     * A query URL keeps email links independent of the site's SEF setting.
     * PublicLink substitutes the opaque token without changing query syntax.
     */
    public const UNSUBSCRIBE_ROUTE = 'index.php?option=com_vidyalms&view=unsubscribe&token={value}';

    /**
     * Where a machine-readable credential is dereferenced.
     *
     * The API mount rather than a site view, because the credential is served
     * by GET /v1/vidyalms/credentials/{uuid} under content negotiation and no
     * site view answers for it. The id inside a signed document is the one
     * URL a wallet will fetch a year from now, so pointing it at a route that
     * does not exist is not a cosmetic defect.
     */
    public const CREDENTIAL_ROUTE = 'api/index.php/v1/vidyalms/credentials';

    /**
     * The component option holding this site's own public root.
     *
     * It exists because Uri::root() is composed from the request and
     * issuance is scheduled work: the queue is drained by a Scheduled Task
     * routine and by cli/joomla.php, neither of which has one. A credential
     * minted there carried an id like
     * http://localhost/cli/joomla.php/index.php/... and, once signed, could
     * never be corrected. live_site answers first where the site sets it;
     * this option is for the site that does not, and the installer records it
     * from the one request that is guaranteed to be a real one.
     */
    public const PARAM_SITE_BASE_URL = 'site_base_url';

    /**
     * The kernel-ready seam.
     *
     * Dispatched once per request, after the CoreKernel exists and before any
     * caller receives it, carrying the kernel as the "kernel" argument. It is
     * the only moment at which a plugin or a Pro package can reach
     * $kernel->registries: register() adds a provider under a new key and
     * throws on a collision, replace() overrides a key the free build already
     * filled and hands back what it displaced so the replacement can delegate
     * to it. There is no unregister(); switching a capability off is a
     * replacement that refuses.
     *
     * Listeners must use the kernel carried by the event. Resolving CoreKernel
     * from the container inside a listener re-enters this factory, because the
     * container caches a shared service only once its factory has returned.
     *
     * The adapter installs its own site policies before dispatching, so a
     * listener that replaces one of the same keys wraps the policy instead of
     * dropping it.
     */
    public const KERNEL_READY_EVENT = KernelReady::JOOMLA_EVENT;

    /**
     * The port-contribution moment, one step before the kernel exists.
     *
     * `Ports` is final and readonly and this adapter builds it, so a paid
     * package holding an implementation of one of the optional ports had
     * nowhere to put it and the organisation console answered that nothing on
     * the site could prove a domain. This is the moment a listener seats one,
     * and it fires before the kernel is constructed because every use case the
     * kernel builds eagerly is holding the port it was given.
     *
     * The certificate PDF renderer arrives here too. The free build ships no
     * PDF engine, so the Pro package seats one and every certificate on a site
     * without it stays HTML.
     *
     * A listener that throws is logged and swallowed, exactly as a kernel-ready
     * listener is: a package must not be able to stop the site booting.
     */
    public const PORTS_READY_EVENT = PortsReady::JOOMLA_EVENT;

    /**
     * The free build's self-enrolment gate, the key SiteSelfEnrolmentRule wraps.
     */
    private const OPEN_ENROLMENT_RULE = 'open';

    /**
     * The scheduled task that runs the expiry sweep, as plg_task_vidyalms
     * names it.
     *
     * Public because it is one half of a fact spelled in two extensions: the
     * plugin advertises the routine under this id and this class reads
     * #__scheduler_tasks for it. If the two drift, nothing throws; the
     * Settings and Dashboard screens simply report that no sweep is scheduled,
     * which is indistinguishable from a site that never set one up. The smoke
     * harness asserts the two are equal for exactly that reason.
     */
    public const SWEEP_TASK_TYPE = 'vidyalms.expire_entitlements';

    /**
     * Set while KERNEL_READY_EVENT is being dispatched. A listener that asks
     * the container for the kernel would otherwise recurse into create()
     * forever; this makes that mistake cost one throwaway kernel instead of
     * the site.
     */
    private static bool $announcing = false;

    private function __construct()
    {
    }

    /**
     * The shared kernel for the current request, from the global container.
     *
     * The component's provider.php is what puts the kernel there, and it only
     * runs once the component is booted. A plugin or a scheduled task may run
     * before that, so this boots the component when the service is missing:
     * callers get a kernel whatever order Joomla woke them in.
     */
    public static function configuration(): \Shondalai\VidyaLMS\Core\Application\Platform\RuntimeConfiguration
    {
        $container = Factory::getContainer();
        $class = \Shondalai\VidyaLMS\Core\Application\Platform\RuntimeConfiguration::class;
        if (!$container->has($class)) {
            Factory::getApplication()->bootComponent(self::COMPONENT);
        }
        return $container->get($class);
    }

    public static function kernel(): CoreKernel
    {
        $container = Factory::getContainer();

        if (!$container->has(CoreKernel::class)) {
            Factory::getApplication()->bootComponent(self::COMPONENT);
        }

        /** @var CoreKernel $kernel */
        $kernel = $container->get(CoreKernel::class);

        return $kernel;
    }

    /**
     * Builds the kernel from the ports already registered on the container.
     * Registered as the CoreKernel factory in admin/services/provider.php.
     */
    public static function create(Container $container): CoreKernel
    {
        // Prefer the registered bundle so the kernel and anything that resolves
        // Ports::class hold the same object, not two views of it.
        /** @var Ports $ports */
        $ports = $container->has(Ports::class) ? $container->get(Ports::class) : self::ports($container);
        // One step before the kernel. A package with an implementation of an
        // optional port seats it here or nowhere, because everything the
        // kernel builds eagerly is handed the port it was given.
        $ports = self::contributePorts($ports, $container);

        $kernel = new CoreKernel($ports, [
            // Dedicated server-only random key; the legacy Joomla site secret may have only 16 bytes.
            'organisation_invitation_secret' => self::organisationInvitationSecret($container),
            'organisation_invitation_url' => self::organisationInvitationUrl(),
            'verification_base_url' => self::verificationBaseUrl(),
            'unsubscribe_base_url' => self::unsubscribeBaseUrl(),
            'envelope_domain' => self::envelopeDomain(),
            // Lazy: the cadence lives in the scheduler's own table, and only
            // two admin routes ever state it. A closure keeps every other
            // request that boots the kernel from paying for that read.
            'expiry_sweep_schedule' => static fn (): ?string => self::expirySweepSchedule(),
            'host_settings_url' => self::hostSettingsUrl(),
            'self_enrolment' => self::selfEnrolmentEnabled(),
            // The three site facts the credential issuer and the {site.*}
            // tokens default from. Joomla knows all three about itself and
            // the core cannot know any of them, and while nothing supplied
            // them every credential this site issued named an anonymous
            // issuer with an empty id and the seeded template rendered the
            // literal string {site.name}.
            'site_name' => self::siteName(),
            'site_url' => self::siteUrl(),
            'site_logo_url' => self::siteLogoUrl(),
            // The two machine-readable credential bases. The core would
            // otherwise derive them as siblings of the verification base,
            // which on this host is a site view: /component/vidyalms/
            // credentials resolves to nothing, and a credential id has to be
            // dereferenceable by a wallet that has only the document.
            'credential_base_url' => self::credentialBaseUrl(),
            'status_list_base_url' => self::statusListBaseUrl(),
        ], configuration: self::configuration());

        self::installSitePolicies($kernel);
        self::announceKernelReady($kernel, $container);
        $kernel->apiKernel()->use(new FinderRefresh(Factory::getApplication()->getDispatcher()));

        return $kernel;
    }

    /**
     * The site owner's switches, expressed as provider overrides so they hold
     * on every surface rather than only the ones this adapter renders.
     */
    private static function installSitePolicies(CoreKernel $kernel): void
    {
        $rules = $kernel->registries->enrolmentRules;

        // replace() insists the key exists, and the free build registers "open"
        // at boot. A build that did not would be a core change, not a reason to
        // fail the request, so the switch simply has nothing to gate.
        if (!$rules->has(self::OPEN_ENROLMENT_RULE)) {
            return;
        }

        $rules->replace(new SiteSelfEnrolmentRule(
            $rules->get(self::OPEN_ENROLMENT_RULE),
            static fn (): bool => self::selfEnrolmentEnabled(),
        ));
    }

    /**
     * Hands the fresh kernel to any plugin that wants to extend the registries.
     *
     * A listener that throws is logged and swallowed: a third-party plugin must
     * not be able to take the site down at boot. Whatever it managed to
     * register before it threw stays registered, which is the same bargain the
     * event bridge makes for domain events.
     */
    /**
     * Lets an installed package seat an optional port, and answers the bundle
     * the kernel is then built from.
     *
     * The event carries the collector and never the ports, so a listener
     * cannot read the site's own database handle out of the payload. Nor can
     * it replace one: `seat()` accepts only the ports on
     * {@see PortsReady::CONTRIBUTABLE}, which is the three identity ports plus
     * the two the adapters leave null, and every other attempt is refused and
     * named by `refused()`. It used to accept any port whose declared type the
     * seated object satisfied, so a listener could substitute the site's own
     * database, mailer or authorisation and nothing said so.
     *
     * A failure here is logged and the original bundle is used, on the same
     * bargain the kernel-ready seam makes: a third-party package must not be
     * able to stop the site booting.
     */
    private static function contributePorts(Ports $ports, Container $container): Ports
    {
        try {
            $collector = PortsReady::collect();

            Factory::getApplication()->getDispatcher()->dispatch(
                self::PORTS_READY_EVENT,
                new GenericEvent(self::PORTS_READY_EVENT, ['collector' => $collector, 'subject' => $collector])
            );

            return $collector->seal($ports);
        } catch (\Throwable $failure) {
            self::log($container, $failure);

            return $ports;
        }
    }

    private static function announceKernelReady(CoreKernel $kernel, Container $container): void
    {
        if (self::$announcing) {
            return;
        }

        self::$announcing = true;

        try {
            Factory::getApplication()->getDispatcher()->dispatch(
                self::KERNEL_READY_EVENT,
                new GenericEvent(self::KERNEL_READY_EVENT, (new KernelReady($kernel))->payload())
            );
        } catch (\Throwable $failure) {
            self::log($container, $failure);
        } finally {
            self::$announcing = false;
        }
    }

    private static function log(Container $container, \Throwable $failure): void
    {
        try {
            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);

            $logger->error('VidyaLMS kernel-ready listener failed: {message}', [
                'event'     => self::KERNEL_READY_EVENT,
                'message'   => $failure->getMessage(),
                'exception' => $failure,
            ]);
        } catch (\Throwable) {
            // No application, no dispatcher, no logger: a CLI script running
            // before Joomla is up still gets its kernel.
        }
    }

    /**
     * The site owner's self-enrolment switch.
     *
     * Read from the component configuration rather than
     * Factory::getApplication()->getParams(), which merges the active menu item
     * over the component options. The API application has no menu to merge, so
     * the merged params answer differently there, and a switch that held on the
     * page but not on POST /enrolments is the bug this replaced. One value,
     * every application.
     */
    public static function selfEnrolmentEnabled(): bool
    {
        return (int) self::configuration()->get('self_enrolment', 1) === 1;
    }

    /** Server-only accessor shared by native composition and the protected installed acceptance proof. */
    public static function organisationInvitationSecret(?Container $container = null): string
    {
        if (!\function_exists('sodium_crypto_secretbox')
            || parse_url(self::organisationInvitationUrl(), PHP_URL_SCHEME) !== 'https') {
            return '';
        }
        try {
            $container ??= Factory::getContainer();
            $storage = $container->get(StoragePort::class);
            if (!$storage instanceof \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage) {
                return '';
            }
            /** @var DatabasePort $database */
            $database = $container->get(DatabasePort::class);
            $pending = $database->selectOne(
                \Shondalai\VidyaLMS\Core\Port\Database\Query::from('org_notification_secrets')->select(['queue_id'])->limit(1)
            );
            return (new OrganisationInvitationKey($storage, JPATH_ROOT))->readOrCreate($pending === null);
        } catch (\Throwable) {
            // Route discovery fails closed. Never expose file paths, permission commands, keys or ciphertext.
            return '';
        }
    }

    /**
     * The services the core needs, in the order the bundle declares
     * them. Every one of them is a shared service on the global container.
     */
    public static function ports(Container $container): Ports
    {
        /** @var DatabasePort $database */
        $database = $container->get(DatabasePort::class);
        /** @var UsersPort $users */
        $users = $container->get(UsersPort::class);
        /** @var AuthorizationPort $authorization */
        $authorization = $container->get(AuthorizationPort::class);
        /** @var StoragePort $storage */
        $storage = $container->get(StoragePort::class);
        /** @var MailerPort $mailer */
        $mailer = $container->get(MailerPort::class);
        /** @var SchedulerPort $scheduler */
        $scheduler = $container->get(SchedulerPort::class);
        /** @var EventBridgePort $eventBridge */
        $eventBridge = $container->get(EventBridgePort::class);
        /** @var CommercePort $commerce */
        $commerce = $container->get(CommercePort::class);
        /** @var TranslatorPort $translator */
        $translator = $container->get(TranslatorPort::class);
        /** @var LocalePort $locale */
        $locale = $container->get(LocalePort::class);
        /** @var CachePort $cache */
        $cache = $container->get(CachePort::class);
        /** @var ClockPort $clock */
        $clock = $container->get(ClockPort::class);
        /** @var RandomPort $random */
        $random = $container->get(RandomPort::class);
        /** @var LoggerInterface $logger */
        $logger = $container->get(LoggerInterface::class);
        /** @var CourseCategoryPort $courseCategories */
        $courseCategories = $container->get(CourseCategoryPort::class);

        return new Ports(
            $database,
            $users,
            $authorization,
            $storage,
            $mailer,
            $scheduler,
            $eventBridge,
            $commerce,
            $translator,
            $cache,
            $clock,
            $random,
            $logger,
            $locale,
            // The recipient's own timezone and language, so quiet hours run
            // in the person's zone and a multilingual site can select their
            // template row. The port had no implementer on either host, so
            // both answered the site defaults for every recipient and a
            // statutory quiet-hours channel refused every send.
            new JoomlaRecipientProfile($container->get(UserFactoryInterface::class)),
            $courseCategories,
            new \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaCourseLinks(self::siteRootUrl()),
            // No PDF port. This package ships no PDF engine and its
            // certificates are HTML, which is what the core renders when no
            // PdfPort exists; designs published as PDF under 1.0.0 are drawn
            // as HTML by the core's stand-in for that key. VidyaLMS Pro seats
            // its renderer at PORTS_READY_EVENT, and a site without Pro, or
            // with a Pro older than that seat, simply keeps the HTML
            // certificates.
            //
            // Named rather than positional, because the three identity ports
            // sit between these two and the members above them, and this
            // adapter implements one of the three. Naming them says which.
            //
            // The asserted-identity port is seated here and not from the
            // kernel-ready seam, and it cannot be seated there: Ports is
            // readonly and is finished before the kernel exists, so
            // kernel-ready is where registries are extended and never where a
            // port arrives. The other two stay at the core's own defaults,
            // which are a refusal and an empty list, because this host
            // verifies no domain and holds no verified address list.
            assertedIdentity: new \VidyaLMS\Component\VidyaLMS\Administrator\Port\HostGroupIdentitySource(
                $database,
                $clock,
                $users,
            ),
            http: self::httpPort(),
            secrets: self::secretsPort($storage),
        );
    }

    /**
     * Secret resolution, which needs the private storage root and refuses
     * without it.
     *
     * A build whose storage port is not this adapter's own gets the core's
     * refusing default rather than a second guess at where a private
     * directory might be: the whole value of `store:` is that the bytes are
     * somewhere a web request cannot fetch, and an implementation that had to
     * guess that would not be able to promise it.
     */
    private static function secretsPort(StoragePort $storage): \Shondalai\VidyaLMS\Core\Port\Secrets\SecretsPort
    {
        if (!$storage instanceof \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage) {
            return new \Shondalai\VidyaLMS\Core\Port\Secrets\NoSecrets();
        }

        return new \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaSecrets($storage);
    }

    /**
     * The outbound seam, wired from the four facts the port says belong to
     * the adapter.
     *
     * Joomla already owns three of them and owns them where a site owner can
     * see them: the proxy is Global Configuration, the CA bundle is whatever
     * the transport was built with, and the transport itself is chosen by
     * HttpFactory from what the host has. The fourth, the outbound host
     * allowlist, is a component option, and an empty one allows nothing.
     */
    private static function httpPort(): \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaHttp
    {
        $params = self::configuration();
        $allowlist = \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaHttp::parseAllowlist(
            (string) $params->get('integrations_http_allowlist', ''),
        );
        $timeout = (int) $params->get(
            'integrations_http_timeout',
            \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaHttp::DEFAULT_TIMEOUT_SECONDS,
        );

        $options = ['userAgent' => self::OUTBOUND_USER_AGENT, 'follow_location' => false];
        $curl = self::proxyCurlOptions();

        if ($curl !== []) {
            $options['transport.curl'] = $curl;
        }

        return new \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaHttp(
            (new HttpFactory())->getHttp($options),
            $allowlist,
            $timeout,
        );
    }

    /**
     * The site's own proxy, as curl options.
     *
     * Joomla's transports do not read Global Configuration's proxy settings by
     * themselves, so an outbound call made without this would bypass the
     * proxy a site behind one is required to use, and would simply fail there.
     * Reading them here is one place rather than one per gateway package.
     *
     * @return array<int, string|int>
     */
    private static function proxyCurlOptions(): array
    {
        try {
            $config = Factory::getContainer()->get('config');
        } catch (\Throwable) {
            return [];
        }

        if (!$config instanceof Registry || !(bool) $config->get('proxy_enable', false)) {
            return [];
        }

        $host = trim((string) $config->get('proxy_host', ''));

        if ($host === '') {
            return [];
        }

        $options = [\CURLOPT_PROXY => $host];
        $port = (int) $config->get('proxy_port', 0);

        if ($port > 0) {
            $options[\CURLOPT_PROXYPORT] = $port;
        }

        $user = trim((string) $config->get('proxy_user', ''));

        if ($user !== '') {
            $options[\CURLOPT_PROXYUSERPWD] = $user . ':' . (string) $config->get('proxy_pass', '');
        }

        return $options;
    }

    /**
     * The public base a certificate verification URL is built on. Configured
     * in the component options; otherwise the site's own verification route.
     */
    public static function verificationBaseUrl(): string
    {
        $configured = trim((string) self::configuration()->get('verification_base_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $root = self::siteRootUrl();

        return $root === '' ? '' : $root . '/' . self::VERIFICATION_ROUTE;
    }

    /**
     * The public base a credential id is built on. Configured in the
     * component options; otherwise this site's own API mount.
     */
    public static function credentialBaseUrl(): string
    {
        $configured = trim((string) self::configuration()->get('credential_base_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $root = self::siteRootUrl();

        return $root === '' ? '' : $root . '/' . self::CREDENTIAL_ROUTE;
    }

    /**
     * Where a Bitstring status list is published. The credential base plus
     * the core's own status segment, because that is the route that serves
     * it; a site that publishes the list somewhere else (a CDN in front of
     * the API is the case) sets the option.
     */
    public static function statusListBaseUrl(): string
    {
        $configured = trim((string) self::configuration()->get('status_list_base_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $credentials = self::credentialBaseUrl();

        return $credentials === '' ? '' : $credentials . '/status';
    }

    /**
     * This site's own public root, resolved so that a scheduled run and a
     * web request compose the same URL.
     *
     * The order is the order of authority. live_site is the root the site
     * itself declares in configuration.php and is the only one available in
     * every context. The component option is the same fact for a site that
     * leaves live_site empty, which is most of them, and the installer
     * records it. Uri::root() comes last and only where there is a request
     * behind it: in a Scheduled Task routine or a cli/joomla.php run it is
     * composed from $_SERVER['SCRIPT_NAME'], which is the path of the CLI
     * entry point, so a credential minted by the queue carried an id nobody
     * could ever dereference and a signed one could never be corrected.
     *
     * An empty answer is honest rather than dangerous: the core refuses to
     * mint a credential id at all and names the option the host has to
     * supply, which is a configuration gap somebody can fix, not a wrong URL
     * frozen into a document.
     */
    public static function siteRootUrl(): string
    {
        $recorded = trim((string) self::configuration()->get(self::PARAM_SITE_BASE_URL, ''));
        if ($recorded !== '') {
            return rtrim($recorded, '/');
        }
        $liveSite = trim((string) Factory::getApplication()->get('live_site', ''));
        if ($liveSite !== '') {
            return rtrim($liveSite, '/');
        }

        return self::hasWebRequest() ? rtrim(Uri::root(), '/') : '';
    }

    /**
     * Whether Uri::root() has a real request to compose from.
     *
     * The site, administrator and API applications do; the console
     * application and anything running under the CLI SAPI do not, and
     * Uri::base() says so itself by branching on PHP_SAPI.
     */
    public static function hasWebRequest(): bool
    {
        if (str_contains(PHP_SAPI, 'cli')) {
            return false;
        }

        try {
            return Factory::getApplication() instanceof WebApplication;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The domain an asynchronous bounce is addressed back to.
     *
     * The default is the domain of the site's own mail from address, which is
     * a stored global configuration value an administrator entered. It is
     * never Uri::root(): that is composed from the request on a site with no
     * live_site set, the queue is drained by a scheduled task with no
     * request, and a console run would then compose a different domain from
     * the same site.
     *
     * A site that receives bounces somewhere else sets the component option,
     * and the channel's provider settings row overrides both.
     */
    /**
     * The site's own name, which Joomla has always known and the core never
     * could. It defaults the credential issuer's name and the {site.name}
     * token; an administrator overrides it through the settings route.
     */
    public static function siteName(): string
    {
        $configured = trim((string) self::configuration()->get('academy_name', ''));
        return $configured !== '' ? $configured : trim((string) Factory::getApplication()->get('sitename', ''));
    }

    /**
     * The site's own public address, as the credential issuer's url and the
     * {site.url} token. One resolver for all of them, so the issuer named on
     * a credential and the host named in its id can never disagree.
     */
    public static function siteUrl(): string
    {
        return self::siteRootUrl();
    }

    public static function organisationInvitationUrl(): string
    {
        return rtrim(self::siteRootUrl(), '/') . '/index.php?option=com_vidyalms&view=organisations';
    }

    /**
     * The site's logo, for the seeded certificate template's logo slot and
     * the credential issuer image. A component option rather than a template
     * parameter: the template a certificate renders under is not the site
     * template, and reading one from the other would break the day the site
     * changes theme.
     */
    public static function siteLogoUrl(): string
    {
        return trim((string) self::configuration()->get('site_logo_url', ''));
    }

    public static function envelopeDomain(): string
    {
        $configured = trim((string) self::configuration()->get('envelope_domain', ''));

        if ($configured !== '') {
            return $configured;
        }

        return trim((string) Factory::getApplication()->get('mailfrom', ''));
    }

    /**
     * The public base of this site's unsubscribe landing page. Configured in
     * the component options; otherwise the site's own unsubscribe route.
     *
     * RFC 8058 requires one HTTPS URI, so a site served over plain HTTP
     * answers an empty string rather than an http:// base: the send path then
     * suppresses the message with a named reason instead of putting an
     * unusable header on it.
     */
    public static function unsubscribeBaseUrl(): string
    {
        $configured = trim((string) self::configuration()->get('unsubscribe_base_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $root = self::siteRootUrl();

        return str_starts_with($root, 'https://') ? $root . '/' . self::UNSUBSCRIBE_ROUTE : '';
    }

    /**
     * The CMS-shop setup destination. LMS settings are edited in the SPA itself.
     */
    public static function hostSettingsUrl(): string
    {
        $root = rtrim(Uri::root(), '/');
        $admin = '/' . basename(JPATH_ADMINISTRATOR);
        if (str_ends_with($root, $admin)) {
            $root = substr($root, 0, -strlen($admin));
        }
        return $root . $admin . '/index.php?option=com_vidyalms&view=settings#/commerce';
    }

    /**
     * How often the expiry sweep actually runs on this site, read from the
     * scheduled task that runs it.
     *
     * Not a constant: the site owner creates the task and chooses its cadence,
     * so the only truthful answer is the one the scheduler holds. No enabled
     * task means no schedule, which is exactly what the Settings and Dashboard
     * screens should say, because the sweep is then not running at all.
     */
    public static function expirySweepSchedule(): ?string
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $type = self::SWEEP_TASK_TYPE;

        $query = $db->createQuery()
            ->select($db->quoteName('execution_rules'))
            ->from($db->quoteName('#__scheduler_tasks'))
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('state') . ' = 1')
            ->bind(':type', $type, ParameterType::STRING)
            ->order($db->quoteName('id') . ' ASC')
            ->setLimit(1);

        $rules = $db->setQuery($query)->loadResult();

        if (!\is_string($rules) || $rules === '') {
            return null;
        }

        $decoded = json_decode($rules, true);

        return \is_array($decoded) ? self::scheduleLabel($decoded) : null;
    }

    /**
     * The scheduler's execution rules as one phrase, read into the Settings
     * screen's "Runs %s." sentence.
     *
     * @param array<string, mixed> $rules
     */
    private static function scheduleLabel(array $rules): ?string
    {
        // The label is a component string and this runs in whichever
        // application asked for the Settings screen, so the catalogue is
        // loaded the way JoomlaTranslator loads it rather than assumed.
        // Language::load() is idempotent, and this is reached only by the two
        // routes that state the schedule.
        Factory::getApplication()->getLanguage()->load(self::COMPONENT, JPATH_ADMINISTRATOR);

        $type = \is_string($rules['rule-type'] ?? null) ? $rules['rule-type'] : '';

        $key = match ($type) {
            'interval-minutes' => 'COM_VIDYALMS_SWEEP_EVERY_MINUTES',
            'interval-hours' => 'COM_VIDYALMS_SWEEP_EVERY_HOURS',
            'interval-days' => 'COM_VIDYALMS_SWEEP_EVERY_DAYS',
            'interval-months' => 'COM_VIDYALMS_SWEEP_EVERY_MONTHS',
            'cron-expression' => 'COM_VIDYALMS_SWEEP_CRON',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        if ($type === 'cron-expression') {
            return Text::_($key);
        }

        $every = (int) ($rules[$type] ?? 0);

        return $every > 0 ? Text::plural($key, $every) : null;
    }
}
