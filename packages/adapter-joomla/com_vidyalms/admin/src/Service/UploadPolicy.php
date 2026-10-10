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
use Shondalai\VidyaLMS\Core\Upload\UploadPolicy as SharedPolicy;
use VidyaLMS\Component\VidyaLMS\Administrator\Helper\VidyaLmsHelper;

/**
 * This site's upload policy: the shared registry, sized from the component
 * options, then whatever this host's extension event did to it.
 *
 * The registry itself, the purposes, the extension allowlist and the media
 * type table are the core's (Core\Upload\UploadPolicy). They were duplicated
 * here and in the WordPress adapter as identical CMS-free PHP, which put the
 * security boundary in two places; two copies of an allowlist diverge, and a
 * divergence there means one host accepts bytes the other refuses. What is
 * left here is what is genuinely Joomla's: the component options that carry
 * the ceilings, and the event that lets an extension add a purpose.
 *
 * ## Extending it
 *
 * The policy is built once per request and handed to onVidyaLmsUploadPolicy
 * before it is used, exactly as the kernel is handed to onVidyaLmsKernelReady:
 *
 *     public function onVidyaLmsUploadPolicy(EventInterface $event): void
 *     {
 *         $policy = $event->getArgument('policy');
 *         $policy->register(new UploadPurpose('caption', ['vtt' => ['text/vtt']], 1048576));
 *     }
 *
 * A listener that throws is caught and the request continues with whatever was
 * registered before it threw, because refusing a course image is not worth a
 * broken screen.
 *
 * @since 1.0.0
 */
final class UploadPolicy
{
    public const EVENT = 'onVidyaLmsUploadPolicy';

    /** The option names the component stores its two ceilings under. */
    public const OPTION_RESOURCE_MB = 'upload_max_resource_mb';

    public const OPTION_IMAGE_MB = 'upload_max_image_mb';

    private function __construct()
    {
    }

    /**
     * The site's policy: the free build's two purposes, sized from the
     * component options, then whatever the extension event did to them.
     */
    public static function forSite(): SharedPolicy
    {
        $policy = self::free();

        try {
            $dispatcher = Factory::getApplication()->getDispatcher();
            $dispatcher->dispatch(
                self::EVENT,
                new GenericEvent(self::EVENT, ['policy' => $policy, 'subject' => $policy]),
            );
        } catch (\Throwable) {
            // A listener that fails leaves the site with the free build's
            // policy, which is the safe direction to fail in.
        }

        return $policy;
    }

    /**
     * The free build's two purposes with no host event applied, sized from the
     * component options. The smoke harness uses this so a site's own listener
     * cannot make it pass or fail.
     */
    public static function free(): SharedPolicy
    {
        return SharedPolicy::free(
            self::megabytes(SharedPolicy::DEFAULT_RESOURCE_MB, self::OPTION_RESOURCE_MB),
            self::megabytes(SharedPolicy::DEFAULT_IMAGE_MB, self::OPTION_IMAGE_MB),
        );
    }

    /**
     * The purpose's own ceiling in bytes, from the component options. PHP's
     * ceiling is applied by the endpoint, not here: this number is the site
     * owner's intent and that one is what the server will actually accept.
     */
    public static function megabytes(int $fallback, string $option): int
    {
        try {
            $configured = (int) \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::configuration()->get($option, $fallback);
        } catch (\Throwable) {
            $configured = $fallback;
        }

        return max(1, $configured) * 1024 * 1024;
    }
}
