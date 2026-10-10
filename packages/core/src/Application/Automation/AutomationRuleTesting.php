<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Automation\StoredRuleTest;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\Presenter\AutomationPresenter;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationReadModel;

/**
 * The wire side of the stored rehearsals: the allowlist, the paging, and the
 * one shape check the expectations grammar owes a client.
 *
 * The expectations are an availability condition tree plus the three engine
 * assertions a tree cannot express, and nothing here invents a fourth: an
 * assertion this engine does not publish is refused by name at save rather
 * than silently ignored by the scorer, which is what a comparison language
 * invented by omission always turns into.
 *
 * Running one goes through RunStoredRuleTest, which runs it inside a rolled
 * back transaction through the same preview path a person uses, so a test can
 * never write to a live site.
 */
final class AutomationRuleTesting
{
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    /** @var list<string> */
    private const TEST_FIELDS = [
        'name',
        'given',
        'given_version',
        'changed',
        'changed_version',
        'expectations',
        'expectations_version',
    ];

    public function __construct(
        private readonly ManageAutomationRules $rules,
        private readonly RunStoredRuleTest $runner,
        private readonly SqlAutomationReadModel $reads,
        private readonly AutomationPresenter $presenter,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(Principal $principal, int $ruleId, int $page, int $perPage): array
    {
        $this->requireManage($principal);
        $this->requireRule($ruleId);
        $perPage = $this->perPage($perPage);
        $page = max(1, $page);
        $result = $this->reads->testPage($ruleId, $perPage, ($page - 1) * $perPage);

        return AutomationPresenter::page(
            array_map($this->presenter->test(...), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function save(Principal $principal, int $ruleId, array $body): array
    {
        $this->requireManage($principal);
        AutomationInput::only($body, self::TEST_FIELDS);
        $rule = $this->requireRule($ruleId);

        $name = $body['name'] ?? null;

        if (!\is_string($name) || trim($name) === '') {
            throw ValidationException::forField('name', 'A stored test needs a name of its own.');
        }

        $given = $body['given'] ?? null;
        $changed = $body['changed'] ?? null;
        $expectations = $body['expectations'] ?? null;

        foreach (['given' => $given, 'changed' => $changed, 'expectations' => $expectations] as $key => $value) {
            if (!\is_array($value)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($key, 'Expected a document.');
            }
        }

        /** @var array<string, mixed> $given */
        /** @var array<string, mixed> $changed */
        /** @var array<string, mixed> $expectations */
        $this->refuseUnknownAssertions($expectations);

        $saved = $this->rules->saveTest($principal, new StoredRuleTest(
            id: null,
            ruleId: $ruleId,
            ruleVersion: (int) $rule['version'],
            name: trim($name),
            given: $given,
            changed: $changed,
            expectations: $expectations,
            createdBy: $principal->userId,
            givenVersion: \is_int($body['given_version'] ?? null) ? $body['given_version'] : 1,
            changedVersion: \is_int($body['changed_version'] ?? null) ? $body['changed_version'] : 1,
            expectationsVersion: \is_int($body['expectations_version'] ?? null)
                ? $body['expectations_version']
                : 1,
        ));

        $id = $saved->id;

        if ($id === null) {
            throw EntityNotFoundException::for('AutomationRuleTest', 0);
        }

        return $this->presentOne($ruleId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function run(Principal $principal, int $ruleId, int $testId): array
    {
        $this->requireManage($principal);
        $this->requireRule($ruleId);
        $this->requireTest($ruleId, $testId);
        $report = $this->runner->execute($principal, $testId);
        $stored = $this->reads->test($testId);

        return [
            'test_id' => $testId,
            'result' => (string) ($report['result'] ?? StoredRuleTest::RESULT_ERRORED),
            'detail' => ($report['detail'] ?? null) === '' ? null : ($report['detail'] ?? null),
            'failures' => array_values(array_map(
                static fn (mixed $entry): string => (string) $entry,
                \is_array($report['failures'] ?? null) ? $report['failures'] : [],
            )),
            'ran_at' => (string) ($stored['last_run_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $expectations
     */
    private function refuseUnknownAssertions(array $expectations): void
    {
        $engine = $expectations['engine'] ?? [];

        if (!\is_array($engine)) {
            throw ValidationException::forField('expectations', 'The engine assertions are a list.');
        }

        foreach ($engine as $assertion) {
            $kind = \is_array($assertion) ? ($assertion['kind'] ?? null) : null;

            if (!\is_string($kind) || !\in_array($kind, StoredRuleTest::engineAssertions(), true)) {
                throw ValidationException::forField(
                    'expectations',
                    sprintf(
                        'Unknown engine assertion. This engine publishes exactly %s.',
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        implode(', ', StoredRuleTest::engineAssertions()),
                    ),
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOne(int $ruleId, int $testId): array
    {
        $row = $this->reads->test($testId);

        if ($row === null || (int) $row['rule_id'] !== $ruleId) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRuleTest', $testId);
        }

        return $this->presenter->test($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRule(int $ruleId): array
    {
        $row = $this->reads->rule($ruleId);

        if ($row === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRule', $ruleId);
        }

        return $row;
    }

    private function requireTest(int $ruleId, int $testId): void
    {
        $row = $this->reads->test($testId);

        if ($row === null || (int) $row['rule_id'] !== $ruleId) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRuleTest', $testId);
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
