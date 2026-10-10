<?php
/**
 * @package     VidyaLMS
 * @subpackage  plg_webservices_vidyalms
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Plugin\Webservices\VidyaLms\Extension;

use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory;

// phpcs:disable PSR1.Files.SideEffects
defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Mounts the VidyaLMS /v1 contract on the Joomla API router.
 *
 * The core owns path matching and authorization, so the whole contract is one
 * greedy tail per verb and every request lands on the same com_vidyalms "rest"
 * controller. Nothing is matched here that the core does not match again:
 * 404, 405 and 401 all come out of the core as application/problem+json, which
 * is what makes the Joomla and WordPress mounts answer identically.
 *
 * CORS preflight is deliberately not handled here. ApiApplication answers
 * OPTIONS itself from the routes matching the path, gated on the site's global
 * CORS setting, and duplicating that would let an extension override the site
 * owner's choice.
 *
 * @since 1.0.0
 */
final class VidyaLms extends CMSPlugin implements SubscriberInterface
{
    /**
     * The mount point of the contract, matching the Joomla server URL in
     * openapi.yaml (https://{host}/api/index.php/v1/vidyalms).
     */
    private const MOUNT = 'v1/vidyalms';

    private const COMPONENT = 'com_vidyalms';

    /**
     * The single controller and task every route resolves to.
     */
    private const TARGET = 'rest.handle';

    /**
     * The verbs the mount claims. PATCH and OPTIONS are here although the
     * contract defines no PATCH operation, so the core answers 405 for one and
     * the API application can answer the CORS preflight for the other.
     *
     * @var  list<string>
     */
    private const VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * The media types this mount negotiates when it cannot ask the core what
     * they are.
     *
     * `ApiApplication::route()` runs its own content negotiation against the
     * route's `format` list **before** anything of ours is dispatched, and
     * answers 406 on a miss. So a route the contract serves under content
     * negotiation is unreachable on this host unless its media type is in
     * that list: `GET /v1/credentials/{uuid}` asks for `application/ld+json`
     * or `image/png`, and both answered 406 from Joomla while the same
     * request through the kernel answered 200.
     *
     * These two are the floor rather than the answer. `application/json` is
     * what every ordinary client of this contract sends and what a request
     * with no Accept header falls back to, and `application/problem+json` is
     * what every refusal is written in, so a build whose kernel will not boot
     * can still say so in the contract's own shape.
     *
     * @var  list<string>
     */
    private const BASELINE = [
        'application/json',
        'application/problem+json',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeApiRoute' => 'onBeforeApiRoute',
        ];
    }

    /**
     * Registers the /v1 mount on the API router.
     *
     * @param   BeforeApiRouteEvent  $event  The routing event carrying the API router.
     */
    public function onBeforeApiRoute(BeforeApiRouteEvent $event): void
    {
        $defaults = $this->defaults();
        $routes   = [];

        // One greedy tail per verb, including the verbs the contract does not
        // define: the core router owns path matching and is therefore the only
        // thing that may answer 404 or 405, so both mounts speak the same
        // problem+json for the same request.
        foreach (self::VERBS as $verb) {
            $routes[] = new Route([$verb], self::MOUNT, self::TARGET, [], $defaults);
            $routes[] = new Route([$verb], self::MOUNT . '/*tail', self::TARGET, [], $defaults);
        }

        $event->getRouter()->addRoutes($routes);
    }

    /**
     * Route defaults.
     *
     * Every route is "public" in Joomla's sense, which is not the same as
     * unauthenticated: it means the API application does not reject the request
     * before dispatch. Authorization is the core's, enforced inside the use
     * cases by AccessGuard, and the controller authenticates a caller who
     * presents credentials. Letting Joomla reject first would answer its own
     * error shape where the contract promises application/problem+json, and
     * would differ from the WordPress mount for the same call.
     *
     * "format" is the content negotiation priority list, not the document
     * format: without it the API application defaults to
     * application/vnd.api+json and answers 406 to the plain application/json
     * every client of this contract sends.
     *
     * @return  array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'component' => self::COMPONENT,
            'public'    => true,
            'format'    => $this->acceptableMediaTypes(),
        ];
    }

    /**
     * What this mount will negotiate, asked of the core rather than held as a
     * list here.
     *
     * The list used to be a `private const` naming the union of the six
     * shipped credential formats. That made a seventh format a change to this
     * plugin: the design's next one is Open Badges 3.0 SVG baking
     * (`image/svg+xml`), and a third party registering
     * `application/vc+sd-jwt` got a 406 from Joomla for a shape the core
     * would have served. A Drupal adapter would have reinvented the same
     * list, and so would anybody who forgot to update it. The core already
     * publishes the answer through the credential format registry, which is
     * where a format registers, so this asks it.
     *
     * Unfiltered on purpose: a format that is installed but cannot serve
     * (a signing package with no key configured) stays acceptable here so the
     * refusal is the core's 406, which names what this build *can* serve,
     * rather than the host's, which names nothing.
     *
     * Booting the kernel is gated on the request being one of ours, because
     * `onBeforeApiRoute` fires for every API request on the site and no other
     * component's call should pay for our container. For our own it costs
     * nothing: the controller boots the same shared instance a moment later.
     *
     * @return  list<string>
     */
    private function acceptableMediaTypes(): array
    {
        return $this->requestIsOurs() ? self::negotiableMediaTypes() : self::BASELINE;
    }

    /**
     * The media types this build can answer with, from the core.
     *
     * Public because it is one half of a fact that has to be checked against
     * the other: `build/smoke.php` compares it with what the kernel says it
     * can serve, and a mount that negotiates a narrower set than the core
     * serves is a 406 the host invented.
     *
     * @return  list<string>
     */
    public static function negotiableMediaTypes(): array
    {
        try {
            $formats = KernelFactory::kernel()->registries->credentialFormats->all();
        } catch (\Throwable) {
            // Never a fatal at routing time: a kernel that will not boot is
            // a 500 the core writes for the request that needed it, not a
            // routing table this plugin failed to build.
            return self::BASELINE;
        }

        $types = self::BASELINE;

        foreach ($formats as $format) {
            $mimeType = $format->mimeType();

            if ($mimeType !== '' && !\in_array($mimeType, $types, true)) {
                $types[] = $mimeType;
            }
        }

        return $types;
    }

    /**
     * Whether the request being routed is addressed to this mount.
     *
     * Read from the URI rather than from the router, because the router has
     * not matched anything yet: this runs before it is given its routes. A
     * false positive costs one kernel boot on a request that then 404s, and
     * a false negative costs the baseline list on a request that was never
     * ours.
     */
    private function requestIsOurs(): bool
    {
        return str_contains(Uri::getInstance()->getPath(), self::MOUNT);
    }
}
