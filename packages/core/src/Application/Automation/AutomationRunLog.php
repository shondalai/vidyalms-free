<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Http\Presenter\AutomationPresenter;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationReadModel;

/**
 * What a rule actually did: the runs, and one run's action log.
 *
 * Rehearsals are listed beside real runs and marked, because an operator
 * asking "did that fire" is owed the previews as well: a preview writes a run
 * row with dry_run true, and hiding it would make the rehearsal invisible on
 * the one screen that reports what happened.
 *
 * A run that belongs to another rule is a 404 on this rule's log rather than a
 * 403, so the path id is validated against the rule the caller named instead of
 * being trusted.
 */
final class AutomationRunLog
{
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly SqlAutomationReadModel $reads,
        private readonly AutomationPresenter $presenter,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function runs(
        Principal $principal,
        int $ruleId,
        ?string $state,
        ?bool $dryRun,
        int $page,
        int $perPage,
    ): array {
        $this->requireManage($principal);
        $this->requireRule($ruleId);
        $perPage = $this->perPage($perPage);
        $page = max(1, $page);
        $result = $this->reads->runPage($ruleId, $state, $dryRun, $perPage, ($page - 1) * $perPage);

        return AutomationPresenter::page(
            array_map($this->presenter->run(...), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function actions(Principal $principal, int $ruleId, int $runId, int $page, int $perPage): array
    {
        $this->requireManage($principal);
        $this->requireRule($ruleId);
        $run = $this->reads->run($runId);

        if ($run === null || (int) $run['rule_id'] !== $ruleId) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRun', $runId);
        }

        $perPage = $this->perPage($perPage);
        $page = max(1, $page);
        $result = $this->reads->actionLogPage($runId, $perPage, ($page - 1) * $perPage);

        return AutomationPresenter::page(
            array_map($this->presenter->actionLogEntry(...), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    private function requireRule(int $ruleId): void
    {
        if ($this->reads->rule($ruleId) === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRule', $ruleId);
        }
    }

    private function requireManage(Principal $principal): void
    {
        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);
    }

    private function perPage(int $perPage): int
    {
        return $perPage < 1 ? self::DEFAULT_PER_PAGE : min($perPage, self::MAX_PER_PAGE);
    }
}
