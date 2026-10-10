<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;

defined('_JEXEC') or die;

/**
 * Static services the administrator screens share: the ACL actions, the shared
 * CoreKernel, the caller as a core Principal, and the display mappings the
 * Import screen needs for the core's import enums.
 *
 * @since 1.0.0
 */
final class VidyaLmsHelper
{
    public const COMPONENT = 'com_vidyalms';

    private function __construct()
    {
    }

    /**
     * The ACL actions declared in access.xml, resolved for the current user.
     */
    public static function getActions(): Registry
    {
        return ContentHelper::getActions(self::COMPONENT);
    }

    /**
     * The Joomla actions that open the administrator screens at all.
     *
     * core.manage is what "manage this component" means to a Joomla
     * administrator, and it implies every VidyaLMS capability except
     * vidyalms.admin. The four operational actions are here because a person
     * who only teaches, grades, enrols or reads reports holds one of them and
     * not core.manage: without them Joomla's own component dispatcher would
     * refuse an instructor at the door, before any screen could show them the
     * one course they instruct.
     *
     * Opening the component is not permission to do anything in it. Every
     * screen is a React mount over the /v1 contract, and the core's
     * AccessGuard and CourseAccess answer each request, which is the only
     * place that can see which course is being addressed.
     *
     * @var list<string>
     */
    public const ADMINISTRATOR_ACTIONS = [
        'core.manage',
        'vidyalms.teach',
        'vidyalms.grade',
        'vidyalms.enrol',
        'vidyalms.reports',
        'vidyalms.manage_groups',
    ];

    /**
     * Whether this identity may open the component's administrator screens.
     *
     * @param User|null $identity
     */
    public static function mayReachAdministrator($identity): bool
    {
        if (!$identity instanceof User || $identity->guest) {
            return false;
        }

        foreach (self::ADMINISTRATOR_ACTIONS as $action) {
            if ($identity->authorise($action, self::COMPONENT)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The kernel registered on the global container by the component's
     * services/provider.php. Every screen shares one instance.
     */
    public static function kernel(): CoreKernel
    {
        $container = Factory::getContainer();

        if (!$container->has(CoreKernel::class)) {
            throw new \RuntimeException(Text::_('COM_VIDYALMS_ERROR_KERNEL_UNAVAILABLE'));
        }

        return $container->get(CoreKernel::class);
    }

    /**
     * Read access to the LMS tables for the list and detail screens. Writes
     * never come through here: they go through the kernel's use cases.
     */
    public static function database(): DatabasePort
    {
        $container = Factory::getContainer();

        if ($container->has(DatabasePort::class)) {
            return $container->get(DatabasePort::class);
        }

        if ($container->has(Ports::class)) {
            return $container->get(Ports::class)->database;
        }

        throw new \RuntimeException(Text::_('COM_VIDYALMS_ERROR_DATABASE_PORT_UNAVAILABLE'));
    }

    /**
     * The private file area. An import upload is stored through this and only
     * its stored path travels on: bytes never reach a use case or the API.
     */
    public static function storage(): StoragePort
    {
        $container = Factory::getContainer();

        if ($container->has(StoragePort::class)) {
            return $container->get(StoragePort::class);
        }

        if ($container->has(Ports::class)) {
            return $container->get(Ports::class)->storage;
        }

        throw new \RuntimeException(Text::_('COM_VIDYALMS_ERROR_STORAGE_PORT_UNAVAILABLE'));
    }

    /**
     * The core's clock. A screen deciding whether a job has stopped
     * responding asks the same source the core does, so the two never
     * disagree about what time it is.
     */
    public static function now(): \DateTimeImmutable
    {
        $container = Factory::getContainer();

        if ($container->has(ClockPort::class)) {
            return $container->get(ClockPort::class)->now();
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * The current Joomla identity as the core sees it.
     */
    public static function principal(): Principal
    {
        $user = Factory::getApplication()->getIdentity();

        if ($user === null || $user->guest || (int) $user->id <= 0) {
            return Principal::guest();
        }

        return Principal::user((int) $user->id);
    }

    /**
     * Asks the core guard, not Joomla's ACL directly, so a screen shows only
     * what the use cases would actually allow.
     */
    public static function can(string $capability): bool
    {
        return self::kernel()->guard->can(self::principal(), $capability);
    }

    /**
     * @param bool $stalled whether the run has stopped writing, which is a
     *                      display state and never the stored status
     */
    public static function importStatusLabel(string $status, bool $stalled = false): string
    {
        if ($stalled) {
            return 'COM_VIDYALMS_IMPORT_STATUS_STALLED';
        }

        return match ($status) {
            ImportJob::STATUS_RUNNING   => 'COM_VIDYALMS_IMPORT_STATUS_RUNNING',
            ImportJob::STATUS_COMPLETED => 'COM_VIDYALMS_IMPORT_STATUS_COMPLETED',
            ImportJob::STATUS_PREVIEWED => 'COM_VIDYALMS_IMPORT_STATUS_PREVIEWED',
            ImportJob::STATUS_FAILED    => 'COM_VIDYALMS_IMPORT_STATUS_FAILED',
            default                     => 'COM_VIDYALMS_IMPORT_STATUS_PENDING',
        };
    }

    /**
     * What a note's level is called on screen. The level is data the core
     * recorded, never something read out of the note's own text, so a course
     * title cannot label itself an error.
     */
    public static function importNoteLabel(string $level): string
    {
        return match ($level) {
            ImportNote::ERROR   => 'COM_VIDYALMS_IMPORT_NOTE_ERROR',
            ImportNote::WARNING => 'COM_VIDYALMS_IMPORT_NOTE_WARNING',
            default             => 'COM_VIDYALMS_IMPORT_NOTE_INFO',
        };
    }

    public static function importStatusBadge(string $status, bool $stalled = false): string
    {
        if ($stalled) {
            return 'bg-warning text-dark';
        }

        return match ($status) {
            ImportJob::STATUS_RUNNING   => 'bg-info text-dark',
            ImportJob::STATUS_COMPLETED => 'bg-success',
            ImportJob::STATUS_PREVIEWED => 'bg-primary',
            ImportJob::STATUS_FAILED    => 'bg-danger',
            default                     => 'bg-secondary',
        };
    }

    /**
     * The shared totals vocabulary, in the order an operator reads it. A key
     * an importer invented is shown as it stands rather than dropped.
     */
    public static function importTotalLabel(string $key): string
    {
        return match ($key) {
            'courses'    => 'COM_VIDYALMS_IMPORT_TOTAL_COURSES',
            'modules'    => 'COM_VIDYALMS_IMPORT_TOTAL_MODULES',
            'lessons'    => 'COM_VIDYALMS_IMPORT_TOTAL_LESSONS',
            'quizzes'    => 'COM_VIDYALMS_IMPORT_TOTAL_QUIZZES',
            'questions'  => 'COM_VIDYALMS_IMPORT_TOTAL_QUESTIONS',
            'enrolments' => 'COM_VIDYALMS_IMPORT_TOTAL_ENROLMENTS',
            'learners'   => 'COM_VIDYALMS_IMPORT_TOTAL_LEARNERS',
            'skipped'    => 'COM_VIDYALMS_IMPORT_TOTAL_SKIPPED',
            default      => '',
        };
    }
}
