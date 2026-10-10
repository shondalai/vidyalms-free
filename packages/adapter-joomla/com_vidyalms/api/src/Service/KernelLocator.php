<?php
/**
 * @package     VidyaLMS
 * @subpackage  com_vidyalms
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Api\Service;

use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory;

// phpcs:disable PSR1.Files.SideEffects
defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The REST surface's way to the one shared CoreKernel.
 *
 * com_vidyalms builds the Ports bundle and registers the kernel as a shared
 * service on the global container in its services/provider.php; the API
 * application, the site application and every plugin then resolve the same
 * instance through KernelFactory, which boots the component first when the
 * service is not there yet. Nothing here constructs a kernel: a second kernel
 * would mean a second event bus and a second idempotency store.
 *
 * @since 1.0.0
 */
final class KernelLocator
{
    private function __construct()
    {
    }

    public static function kernel(): CoreKernel
    {
        return KernelFactory::kernel();
    }
}
