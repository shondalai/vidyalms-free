<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Shondalai\VidyaLMS\Core\Port\Users\RecipientProfilePort;

/**
 * A recipient's own timezone and language, from the Joomla user's parameters.
 *
 * The port had no implementer anywhere, so both shipped hosts answered the
 * site defaults for every recipient. Two consequences follow, and neither is
 * speculative: notification_templates is keyed by locale and resolved at
 * send, so a multilingual site could only ever select the site row; and a
 * channel whose quiet-hours window is statutory refuses to send when no
 * per-recipient timezone is available, so the paid SMS channel refused for
 * every recipient on every install.
 *
 * Joomla stores both on the user's own parameters, and both are legitimately
 * empty: a user who never chose one inherits the site's, which is what null
 * means here and is what the fixed default already answered.
 *
 * @since 1.0.0
 */
final class JoomlaRecipientProfile implements RecipientProfilePort
{
    public function __construct(private readonly ?UserFactoryInterface $users = null)
    {
    }

    public function timezoneFor(int $userId): ?string
    {
        return $this->parameter($userId, 'timezone');
    }

    public function localeFor(int $userId): ?string
    {
        return $this->parameter($userId, 'language');
    }

    private function parameter(int $userId, string $name): ?string
    {
        if ($userId < 1) {
            return null;
        }

        $factory = $this->users ?? Factory::getContainer()->get(UserFactoryInterface::class);
        $user = $factory->loadUserById($userId);

        if ((int) $user->id !== $userId) {
            return null;
        }

        $value = trim((string) $user->getParam($name, ''));

        return $value === '' ? null : $value;
    }
}
