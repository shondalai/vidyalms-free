<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class AddModule
{
    public function __construct(
        private readonly ModuleRepository $modules,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(
        Principal $principal,
        int $courseId,
        string $title,
        ?string $summary = null,
        string $state = Module::STATE_PUBLISHED,
    ): Module {
        $this->access->require($principal, $courseId, Capability::TEACH);

        $title = trim($title);

        if ($title === '' || \strlen($title) > 255) {
            throw ValidationException::forField('title', 'A non-empty title of at most 255 characters is required.');
        }

        if (!\in_array($state, [Module::STATE_PUBLISHED, Module::STATE_DRAFT], true)) {
            throw ValidationException::forField('state', 'The state must be published or draft.');
        }

        $module = new Module(
            id: 0,
            courseId: $courseId,
            title: $title,
            summary: $summary,
            position: $this->modules->nextPosition($courseId),
            state: $state,
        );

        return $module->withId($this->modules->add($module));
    }
}
