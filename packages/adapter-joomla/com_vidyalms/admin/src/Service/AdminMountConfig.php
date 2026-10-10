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
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/**
 * Builds the data-vy-config JSON of the two administrator apps: the admin
 * screens (#vidyalms-admin) and the instructor workbench
 * (#vidyalms-instructor). The shape is the mount contract of
 * docs/technical/05-frontend-strategy.md, shared with the WordPress adapter;
 * everything the apps know about the host arrives through this object and
 * nothing else.
 *
 * The api block uses the "query" URL mode against the administrator
 * passthrough task, because the administrator session is not the site session
 * and carries its own form token. The base therefore ends in "&path=" and the
 * SDK appends the URL-encoded contract path.
 *
 * Every URL here is a plain index.php URL rather than a routed one. These are
 * administrator URLs, where SEF never applies, and two of them are templates
 * carrying a {course_id} or {slug} token the app substitutes: a router would
 * URL-encode the braces and the token would stop matching.
 *
 * @since 1.0.0
 */
final class AdminMountConfig
{
    public const ADMIN_MOUNT = 'vidyalms-admin';

    public const WORKBENCH_MOUNT = 'vidyalms-instructor';

    /**
     * The version of the mount contract this adapter writes, asserted by
     * the bundle. Raise it with packages/ui's MOUNT_CONTRACT when the shape
     * changes in a way an older bundle cannot read: without it the failure
     * mode is a silently missing key rather than a sentence anybody can act
     * on.
     */
    public const CONTRACT = 1;

    /**
     * Fired with the assembled array and the mount it is for, so a package
     * that needs one value in a screen of its own does not have to edit the
     * free build. Mirrors the upload policy event.
     *
     *     public function onVidyaLmsMountConfig(EventInterface $event): void
     *     {
     *         if ($event->getArgument('mount') === AdminMountConfig::ADMIN_MOUNT) {
     *             $config = $event->getArgument('config');
     *             $config['seats'] = ['remaining' => 12];
     *             $event->setArgument('config', $config);
     *         }
     *     }
     *
     * A listener that throws leaves the config as the free build built it,
     * because a broken screen is worse than a missing extra.
     */
    public const EVENT = 'onVidyaLmsMountConfig';

    private function __construct()
    {
    }

    /**
     * The admin app's contract for one screen.
     *
     * @return array<string, mixed> ready for json_encode
     */
    public static function admin(string $screen): array
    {
        $registry   = AdminScreens::registry();
        $definition = $registry->get($screen);

        if ($definition === null) {
            throw new \RuntimeException(Text::_('COM_VIDYALMS_ERROR_ADMIN_SCREEN_NOT_FOUND'), 404);
        }
        if (!AdminScreens::allows($definition)) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $screens = [];

        foreach ($registry->all() as $registered) {
            if (AdminScreens::allows($registered)) {
                $screens[$registered->key] = self::adminUrl($registered->query);
            }
        }

        return self::extended(self::ADMIN_MOUNT, self::shared() + [
            'screen' => $definition->key,
            'shop' => self::shopSetup(),
            'importTransport' => [
                'url' => self::adminUrl('task=import.workspace'),
                'nonceName' => Session::getFormToken(),
                'nonce' => '1',
            ],
            'routes' => self::servedRoutes(),
            'uploadPurposes' => self::uploadPurposes(),
            'urls' => [
                'workbench' => self::workbenchTemplate(),
                'preview'   => self::previewTemplate(),
                // GET /v1/admin/settings is read only by contract, so
                // everything editable is the component's own Options screen.
                'settings'  => self::adminUrl('option=com_vidyalms&view=settings', false),
                'upload'    => self::uploadUrl(),
                'screens'   => $screens,
                'nativeImport' => self::adminUrl('view=import'),
            ],
        ]);
    }

    /**
     * The workbench's contract. A null course id renders the caller's course
     * list instead of one course, which is what "view=workbench" without an
     * id means.
     *
     * @param string $slug the course slug, empty when the caller may not read
     *                     the course; the app then asks the API and renders
     *                     whatever it is refused with
     *
     * @return array<string, mixed> ready for json_encode
     */
    public static function workbench(?int $courseId, string $slug = ''): array
    {
        return self::extended(self::WORKBENCH_MOUNT, self::shared() + [
            'shop' => self::shopSetup(),
            'course' => $courseId === null || $courseId <= 0
                ? null
                : ['id' => $courseId, 'slug' => $slug],
            'uploadPurposes' => self::uploadPurposes(),
            'routes' => self::servedRoutes(),
            'urls' => [
                'courses'   => self::adminUrl('view=courses'),
                'emailTemplates' => self::emailTemplatesUrl(),
                ...(Factory::getApplication()->getIdentity()?->authorise('core.manage', 'com_vidyalms') === true
                    ? ['certificateTemplates' => self::certificateTemplatesUrl()] : []),
                'workbench' => self::workbenchTemplate(),
                'preview'   => self::previewTemplate(),
                'upload'    => self::uploadUrl(),
            ],
        ]);
    }

    /**
     * The config JSON exactly as the mount attribute wants it. Slashes stay
     * unescaped so the URLs read naturally; the caller HTML-escapes the
     * result for the attribute context.
     *
     * @param array<string, mixed> $config
     */
    public static function encode(array $config): string
    {
        return (string) json_encode($config, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    public static function shopSetup(): array
    {
        $installed = ComponentHelper::isEnabled('com_easycommerce');
        $integration = PluginHelper::isEnabled('system', 'vidyalms_easycommerce');
        $identity = Factory::getApplication()->getIdentity();
        $manageShop = $installed && $identity?->authorise('core.manage', 'com_easycommerce') === true;
        $manageMappings = $identity?->authorise('core.manage', 'com_vidyalms') === true;

        return [
            'name' => 'EasyCommerce',
            'installed' => $installed,
            'integrationEnabled' => $installed && $integration,
            'productsUrl' => $manageShop
                ? self::adminUrl('option=com_easycommerce#/products', false) : null,
            'settingsUrl' => $manageShop
                ? self::adminUrl('option=com_easycommerce#/settings', false) : null,
            'mappingUrl' => $manageMappings ? KernelFactory::hostSettingsUrl() : null,
            'mappingApiUrl' => $manageMappings && Factory::getApplication()->isClient('administrator')
                ? self::adminUrl('task=shop.mappings') : null,
        ];
    }

    /**
     * The half every app shares: how to reach the API, who is asking, and in
     * which language.
     *
     * Public and parameterised because the site's own instructor page mounts
     * the same bundle and needs the same three answers. Restating them there
     * meant a key added to the contract could be added to one builder and not
     * the other, and only a live smoke run comparing key sets would notice.
     * The URL half legitimately differs per surface and is what the caller
     * passes; the contract, user and locale half has one implementation.
     *
     * @param array<string, string>|null $headers
     *
     * @return array<string, mixed>
     */
    public static function shared(?string $apiBase = null, ?string $mode = null, ?array $headers = null): array
    {
        $app      = Factory::getApplication();
        $identity = $app->getIdentity();

        return [
            'contract' => self::CONTRACT,
            'api' => [
                'base'    => $apiBase ?? self::adminUrl('task=api.route&path='),
                'mode'    => $mode ?? 'query',
                'headers' => $headers ?? [ApiPassthrough::CSRF_HEADER => Session::getFormToken()],
            ],
            'user' => [
                'id'   => (int) ($identity->id ?? 0),
                'name' => (string) ($identity->name ?? ''),
            ],
            'locale' => $app->getLanguage()->getTag(),
            'messages' => self::messages(),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        static $messages;
        if ($messages !== null) {
            return $messages;
        }
        $language = Factory::getApplication()->getLanguage();
        $language->load('com_vidyalms', JPATH_ADMINISTRATOR);
        return $messages = \Shondalai\VidyaLMS\Core\I18n\UiMessages::translated(
            static function (string $key, string $english) use ($language): string {
                $constant = \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaTranslator::constantFor($key);
                $value = $language->_($constant);
                return $value === $constant ? $english : $value;
            },
        );
    }

    /**
     * Every contract path this install actually serves, as its template.
     *
     * The workbench bundle and this extension ship separately, and a paid or
     * third-party package adds routes from a kernel-ready listener, so which
     * of the contract's paths exist here is decided at boot and nowhere else.
     * Without this key the bundle had to guess, and it guessed wrong in the
     * one direction that matters: the quiz export control was on the contract,
     * in the generated SDK and in the shipped workbench, and answered 404 on
     * every site without the package that serves it. A control that would 404
     * is worse than no control.
     *
     * It is a **discovery** key and never a permission key. Who may export is
     * still the core's answer to the request, which the app renders as a
     * permission state; this only says whether there is anything there to ask.
     * The kernel is the one that knows, so the kernel is asked, and this
     * adapter names no path.
     *
     * Public because the site's own instructor page mounts the same bundle and
     * has to answer the same question; two copies would be two answers.
     *
     * @return list<string>
     */
    public static function servedRoutes(): array
    {
        try {
            $kernel = KernelFactory::kernel();
            return array_values(array_filter($kernel->apiKernel()->servedTemplates(), static function (string $path) use ($kernel): bool {
                if ($path === '/manage/courses/{id}/reports/item-analysis') {
                    return in_array('item_analysis', $kernel->registries->reports->keys(), true);
                }
                if ($path === '/admin/users') {
                    return Factory::getApplication()->getIdentity()?->authorise('core.manage', 'com_vidyalms') === true;
                }
                return true;
            }));
        } catch (\Throwable) {
            // A site that cannot build a kernel has a larger problem than a
            // missing button, and the mount still renders with the app's
            // "nothing is offered" default.
            return [];
        }
    }

    /**
     * What this site actually accepts, from the policy the upload endpoint
     * will use. The picker had a copy of the free build's ceilings, so a
     * site that raised one could not use the extra capacity: the control
     * refused before sending. Extension hooks have already run, so a
     * purpose a plugin registered is offered too.
     *
     * Public because the site's own instructor page writes the same workbench
     * contract from the site application, and two copies of this list would
     * be two answers to "what does this site accept".
     *
     * @return list<array<string, mixed>>
     */
    public static function uploadPurposes(): array
    {
        try {
            $policy = UploadPolicy::forSite();
        } catch (\Throwable) {
            return [];
        }

        $purposes = [];

        foreach ($policy->keys() as $key) {
            $purpose = $policy->purpose($key);

            if ($purpose === null) {
                continue;
            }

            $purposes[] = [
                'key' => $purpose->key,
                // The smaller of the purpose's own ceiling and PHP's, which
                // is the number the endpoint will actually enforce.
                'max_bytes' => UploadLimits::effectiveCeilingBytes($purpose->maxBytes),
                'extensions' => $purpose->extensions(),
            ];
        }

        return $purposes;
    }

    /**
     * The same hook, for a mount this class did not assemble. The site's
     * instructor page writes the workbench contract from the site
     * application, and a listener that extends the workbench must reach it
     * there too: an extension seam that fires on one of two pages rendering
     * the same mount is a seam nobody can rely on.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function extendedFor(string $mount, array $config): array
    {
        return self::extended($mount, $config);
    }

    /**
     * One hook over the assembled config, per mount.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function extended(string $mount, array $config): array
    {
        try {
            $event = new GenericEvent(self::EVENT, [
                'mount' => $mount,
                'config' => $config,
                'subject' => $mount,
            ]);
            Factory::getApplication()->getDispatcher()->dispatch(self::EVENT, $event);
            $extended = $event->getArgument('config');
        } catch (\Throwable) {
            return $config;
        }

        // The contract and the api block are this adapter's to state; a
        // listener that replaced either would break the mount it was trying
        // to extend.
        if (!\is_array($extended)) {
            return $config;
        }

        /** @var array<string, mixed> $extended */
        return $extended + $config;
    }

    private static function workbenchTemplate(): string
    {
        return self::adminUrl('view=workbench&id={course_id}');
    }

    /**
     * Where the file picker posts a multipart form. It is a task URL of this
     * application, not a contract path: no bytes travel through the contract,
     * so the SDK's api base cannot reach it and the apps are told the URL
     * outright. The same X-CSRF-Token the api block carries guards it.
     */
    private static function uploadUrl(): string
    {
        return self::adminUrl('task=api.upload');
    }

    /**
     * Preview as learner opens the player, which is a site page. It is built
     * from the site root rather than routed, because the administrator router
     * builds administrator URLs and SEF routing is a site concern.
     */
    private static function previewTemplate(): string
    {
        return self::siteRoot() . 'index.php?option=com_vidyalms&view=player&slug={slug}';
    }

    /** The same administrator destination from either a site or admin workbench. */
    public static function emailTemplatesUrl(): string
    {
        return self::siteRoot() . basename(JPATH_ADMINISTRATOR)
            . '/index.php?option=com_vidyalms&view=screen&screen=email-templates';
    }

    /** Published certificate design administration, never an instructor permission grant. */
    public static function certificateTemplatesUrl(): string
    {
        return self::siteRoot() . basename(JPATH_ADMINISTRATOR)
            . '/index.php?option=com_vidyalms&view=screen&screen=certificates';
    }

    /** @param bool $component whether to prefix the component option */
    private static function adminUrl(string $query, bool $component = true): string
    {
        return Uri::base(true) . '/index.php?' . ($component ? 'option=com_vidyalms&' : '') . $query;
    }

    /**
     * Uri::root() answers with the administrator directory appended while the
     * administrator application is the one running, because it is derived
     * from Uri::base(). The site root is that with the administrator segment
     * taken back off, which is exact: the segment is the base name of
     * JPATH_ADMINISTRATOR, whether Uri::base built it from live_site or from
     * the script name.
     */
    private static function siteRoot(): string
    {
        $root = rtrim(Uri::root(), '/');

        if (!Factory::getApplication()->isClient('administrator')) {
            return $root . '/';
        }

        $segment = '/' . basename(JPATH_ADMINISTRATOR);

        if (str_ends_with($root, $segment)) {
            $root = substr($root, 0, -\strlen($segment));
        }

        return $root . '/';
    }
}
