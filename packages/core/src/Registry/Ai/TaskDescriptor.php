<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * The static half of a task, which is all a placement predicate may see.
 *
 * A placement declares a predicate over this rather than a list of task keys,
 * and that is the whole extensibility argument: a placement that named keys
 * would make adding the eighteenth paid task or any third-party task an edit
 * to a free provider inside the free bundle. Over a descriptor, a task
 * reaches a shipped panel with zero edits.
 *
 * It carries no provider instance and no registry handle, so a predicate
 * cannot resolve its way back to the task and call it.
 */
final class TaskDescriptor
{
    /** @var list<string> */
    public readonly array $surfaceKeys;

    /**
     * @param list<string> $surfaceKeys the surfaces the task declared itself fit for
     */
    private function __construct(
        public readonly string $taskKey,
        array $surfaceKeys,
        public readonly ?string $draftKind,
        public readonly string $scopeLevel,
        public readonly ?string $targetExtensionPoint,
        public readonly string $contentType,
    ) {
        $this->surfaceKeys = $surfaceKeys;
    }

    /**
     * @param list<string> $surfaceKeys
     */
    public static function of(
        string $taskKey,
        array $surfaceKeys = [],
        ?string $draftKind = null,
        string $scopeLevel = 'course',
        ?string $targetExtensionPoint = null,
        string $contentType = 'plain_text',
    ): self {
        return new self($taskKey, $surfaceKeys, $draftKind, $scopeLevel, $targetExtensionPoint, $contentType);
    }

    /** Whether the task said it belongs on this surface at all. */
    public function fitsSurface(string $surfaceKey): bool
    {
        return \in_array($surfaceKey, $this->surfaceKeys, true);
    }

    /**
     * Whether accepting this task's output could ever write an artefact. A
     * descriptor with no draft kind is display only by construction.
     */
    public function producesArtefact(): bool
    {
        return $this->draftKind !== null && $this->targetExtensionPoint !== null;
    }
}
