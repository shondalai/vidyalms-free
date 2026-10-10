<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class UpdateModule
{
    public function __construct(
        private readonly ModuleRepository $modules,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @param array<string, mixed> $changes accepts title, summary and state;
     *                                      position changes go through
     *                                      ReorderModules
     */
    public function execute(Principal $principal, int $moduleId, array $changes): Module
    {
        $this->access->requireSignedIn($principal);

        $module = $this->modules->byId($moduleId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Module', $moduleId);

        $this->access->require($principal, $module->courseId, Capability::TEACH);

        foreach (array_keys($changes) as $key) {
            if (!\in_array($key, ['title', 'summary', 'state'], true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField((string) $key, 'This field cannot be updated.');
            }
        }

        $title = $module->title;

        if (\array_key_exists('title', $changes)) {
            $value = $changes['title'];

            if (!\is_string($value) || trim($value) === '' || \strlen(trim($value)) > 255) {
                throw ValidationException::forField('title', 'A non-empty title of at most 255 characters is required.');
            }

            $title = trim($value);
        }

        $summary = $module->summary;

        if (\array_key_exists('summary', $changes)) {
            $value = $changes['summary'];

            if ($value !== null && !\is_string($value)) {
                throw ValidationException::forField('summary', 'A string or null is required.');
            }

            $summary = $value;
        }

        $state = $module->state;

        if (\array_key_exists('state', $changes)) {
            $value = $changes['state'];

            // Published and draft only: this is also how an archived module
            // is restored. Archiving goes through ArchiveModule, which is the
            // only path that also archives the module's lessons.
            if (!\is_string($value) || !\in_array($value, [Module::STATE_PUBLISHED, Module::STATE_DRAFT], true)) {
                throw ValidationException::forField(
                    'state',
                    'The state must be published or draft; archive a module with DELETE and restore it by setting one of these.',
                );
            }

            $state = $value;
        }

        $updated = new Module($module->id, $module->courseId, $title, $summary, $module->position, $state);
        $this->modules->update($updated);

        return $updated;
    }
}
