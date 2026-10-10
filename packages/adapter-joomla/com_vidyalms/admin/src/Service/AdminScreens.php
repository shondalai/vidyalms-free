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

/**
 * Builds the screen registry owned by the administrator host.
 *
 * Packages add screens through onVidyaLmsAdminScreens. Each listener is
 * isolated so one broken add-on cannot remove the core screens or another
 * add-on's contribution.
 *
 * @since 1.4.0
 */
final class AdminScreens
{
    public const EVENT = 'onVidyaLmsAdminScreens';

    private function __construct()
    {
    }

    public static function registry(): AdminScreenRegistry
    {
        $registry = new AdminScreenRegistry();

        foreach (self::core() as $screen) {
            $registry->register($screen);
        }

        try {
            $dispatcher = Factory::getApplication()->getDispatcher();
        } catch (\Throwable $error) {
            ComponentLog::error('The administrator screen seam could not reach a dispatcher: ' . $error->getMessage());

            return $registry;
        }

        foreach (self::listeners($dispatcher) as $listener) {
            $event = new GenericEvent(self::EVENT, [
                'registry' => $registry,
                'subject'  => $registry,
            ]);

            try {
                $listener($event);
            } catch (\Throwable $error) {
                ComponentLog::error(sprintf(
                    'A listener on %s failed and its administrator screens were skipped: %s',
                    self::EVENT,
                    $error->getMessage(),
                ));
            }
        }

        return $registry;
    }

    /**
     * @return list<AdminScreenDefinition>
     */
    private static function core(): array
    {
        return [
            new AdminScreenDefinition('organisations', 'view=screen&screen=organisations',
                'COM_VIDYALMS_MANAGER_ORGANISATIONS', 'users vidyalms', ['core.manage', 'vidyalms.manage_groups']),
            new AdminScreenDefinition('reports', 'view=screen&screen=reports',
                'COM_VIDYALMS_MANAGER_REPORTS', 'chart vidyalms', ['core.manage', 'vidyalms.reports']),
            new AdminScreenDefinition('operations', 'view=screen&screen=operations',
                'COM_VIDYALMS_MANAGER_OPERATIONS', 'cogs vidyalms', ['core.manage']),
            new AdminScreenDefinition('import', 'view=screen&screen=import',
                'COM_VIDYALMS_MANAGER_IMPORT_CENTER', 'upload vidyalms', ['core.manage']),
            new AdminScreenDefinition('dashboard', 'view=dashboard', 'COM_VIDYALMS_MANAGER_DASHBOARD', 'home-2 vidyalms', ['core.manage']),
            new AdminScreenDefinition('courses', 'view=courses', 'COM_VIDYALMS_MANAGER_COURSES', 'book vidyalms'),
            new AdminScreenDefinition('categories', 'view=screen&screen=categories', 'COM_VIDYALMS_MENU_CATEGORIES', 'folder vidyalms', ['core.manage', 'core.edit']),
            new AdminScreenDefinition('enrolments', 'view=enrolments', 'COM_VIDYALMS_MANAGER_ENROLMENTS', 'users vidyalms'),
            new AdminScreenDefinition('learners', 'view=learners', 'COM_VIDYALMS_MANAGER_LEARNERS', 'user vidyalms'),
            new AdminScreenDefinition('certificates', 'view=certificates', 'COM_VIDYALMS_MANAGER_CERTIFICATES', 'star vidyalms'),
            new AdminScreenDefinition('notifications', 'view=notifications', 'COM_VIDYALMS_MANAGER_NOTIFICATIONS', 'bell vidyalms'),
            new AdminScreenDefinition('email-templates', 'view=screen&screen=email-templates', 'COM_VIDYALMS_MANAGER_EMAIL_TEMPLATES', 'envelope vidyalms'),
            new AdminScreenDefinition('automations', 'view=screen&screen=automations',
                'COM_VIDYALMS_MANAGER_AUTOMATIONS', 'cogs vidyalms', ['core.manage']),
            // The AI screen. Free on every build and gated on manage, because
            // the run log, the spend and the data-use policy are the three
            // things a site owner reads and nobody else should.
            new AdminScreenDefinition('ai', 'view=screen&screen=ai',
                'COM_VIDYALMS_MANAGER_AI', 'bolt vidyalms', ['core.manage']),
            new AdminScreenDefinition('settings', 'view=settings', 'COM_VIDYALMS_MANAGER_SETTINGS', 'options vidyalms'),
        ];
    }

    /**
     * @return list<callable>
     */
    private static function listeners(object $dispatcher): array
    {
        if (!method_exists($dispatcher, 'getListeners')) {
            return [
                static function (GenericEvent $event) use ($dispatcher): void {
                    if (method_exists($dispatcher, 'dispatch')) {
                        $dispatcher->dispatch(self::EVENT, $event);
                    }
                },
            ];
        }

        /** @var mixed $listeners */
        $listeners = $dispatcher->getListeners(self::EVENT);

        return \is_array($listeners) ? array_values(array_filter($listeners, '\is_callable')) : [];
    }

    public static function allows(AdminScreenDefinition $screen): bool
    {
        if ($screen->requiredAnyActions === []) {
            return true;
        }
        $identity = Factory::getApplication()->getIdentity();
        if ($identity === null || $identity->guest) {
            return false;
        }
        foreach ($screen->requiredAnyActions as $action) {
            if ($identity->authorise($action, 'com_vidyalms')) {
                return true;
            }
        }
        return false;
    }
}
