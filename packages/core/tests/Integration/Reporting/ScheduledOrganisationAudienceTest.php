<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Reporting\ReportExportPipeline;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportSchedule;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Notification\Audience\OrgAdminsAudience;
use Shondalai\VidyaLMS\Core\Provider\Report\OrgRosterReport;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ScheduleReportsTask;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * An organisation audience on an organisation-anchored schedule.
 *
 * Found by the first live clock run of `reports.schedule` on a real Joomla
 * site: a saved, enabled organisation schedule was picked up by the host's own
 * task runner, wrote no run, recorded no error and advanced to its next
 * boundary. Nothing was delivered and nothing said so.
 *
 * `recipientsOf()` built its `AudienceContext` without the tenant the same
 * `serve()` had already witnessed, so `$context->tenant()` was null for every
 * schedule. Every organisation-tier audience reads that tenant first and
 * yields nothing without one, and the audience registry's published miss
 * direction is to resolve empty rather than broadcast, so an unanswerable
 * audience and a genuinely empty one were the same thing from outside.
 *
 * The existing coverage could not see it: every scheduled-report test used a
 * named-list audience, which needs no tenant.
 */
final class ScheduledOrganisationAudienceTest extends TestCase
{
    private const OWNER = 1;
    private const LEARNER = 50;

    private TestPorts $env;
    private CoreKernel $kernel;
    private int $organisationId = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::OWNER, Capability::MANAGE);
        $this->env->authorization->grant(self::OWNER, Capability::VIEW_REPORTS);
        $this->env->authorization->grant(self::OWNER, Capability::MANAGE_GROUPS);
        $this->env->users->add(self::OWNER, 'Ola Owner', 'ola@example.test');
        $this->env->users->add(self::LEARNER, 'Lena Learner', 'lena@example.test');

        $this->kernel = new CoreKernel($this->env->ports);
        $this->seed();
    }

    /**
     * The schedule the clock picks up resolves the tenant's administrators and
     * serves them.
     */
    public function testAnOrganisationAudienceResolvesTheTenantOnAScheduledRun(): void
    {
        $this->saveOrganisationSchedule();

        $summary = $this->task()->run(new ScheduledTaskRun(1, [], Principal::user(self::OWNER)));

        self::assertStringContainsString('served=1', $summary);
        self::assertStringContainsString('runs=1', $summary);

        $run = $this->env->database->selectOne(Query::from('report_runs'));

        self::assertNotNull($run, 'The organisation audience resolved nobody, so the schedule delivered nothing.');
        self::assertSame('done', (string) $run['state']);
        self::assertSame(self::OWNER, (int) $run['rendered_as']);
        self::assertSame($this->organisationId, (int) $run['organisation_id']);
    }

    /**
     * And the silence is what made it hard to find: the schedule advanced with
     * no error at all, so an operator reading the row saw a healthy schedule.
     *
     * This asserts the shape the fix produces rather than the one it removed:
     * the run count moves because work was done, not because nothing was.
     */
    public function testTheScheduleAdvancesBecauseItDeliveredAndNotBecauseItFoundNobody(): void
    {
        $this->saveOrganisationSchedule();
        $this->task()->run(new ScheduledTaskRun(1, [], Principal::user(self::OWNER)));

        $row = $this->env->database->selectOne(Query::from('report_schedules'));

        self::assertNotNull($row);
        self::assertSame(1, (int) $row['run_count']);
        self::assertNull($row['last_error']);
        self::assertSame(
            1,
            $this->env->database->count(Query::from('report_runs')->where('schedule_id', '=', (int) $row['id'])),
            'A schedule that advanced without writing a run is the failure this test exists for.',
        );
    }

    /**
     * A schedule anchored to no organisation passes no tenant and is unchanged,
     * so the site path the fix did not touch is held here too.
     */
    public function testASiteScheduleStillResolvesItsAudienceWithNoTenant(): void
    {
        $this->kernel->registries->audiences->register(new TwoNamedRecipients([self::OWNER]));
        $this->kernel->reportSchedules->save(ReportSchedule::create(
            'Site nightly',
            \Shondalai\VidyaLMS\Core\Provider\Report\SiteLearningReport::KEY,
            TwoNamedRecipients::KEY,
            $this->env->clock->now()->modify('-1 day'),
            self::OWNER,
            'csv',
            'notification-link',
            [],
            [],
            ReportSchedule::RECURRENCE_DAILY,
            1,
            0,
            'UTC',
            ReportSchedule::RENDER_AS_RECIPIENT,
            ReportSchedule::EMPTY_SEND,
            null,
            null,
            null,
            ReportSchedule::STATE_ENABLED,
        ));

        $summary = $this->task()->run(new ScheduledTaskRun(1, [], Principal::user(self::OWNER)));

        self::assertStringContainsString('runs=1', $summary);
        self::assertSame(1, $this->env->database->count(Query::from('report_runs')));
    }

    private function saveOrganisationSchedule(): void
    {
        $now = $this->env->clock->now();

        $this->env->database->insert('report_schedules', [
            'title' => 'Tenant nightly roster',
            'report_key' => OrgRosterReport::KEY,
            'format_key' => 'csv',
            'delivery_key' => 'notification-link',
            'definition' => null,
            'definition_version' => 1,
            'audience_key' => OrgAdminsAudience::KEY,
            'audience_config' => null,
            'audience_config_version' => 1,
            'recurrence' => ReportSchedule::RECURRENCE_DAILY,
            'recurrence_day' => 1,
            'run_at_minute' => 0,
            'timezone' => 'UTC',
            'starts_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
            'next_run_at' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
            'render_as' => ReportSchedule::RENDER_AS_RECIPIENT,
            'empty_policy' => ReportSchedule::EMPTY_SEND,
            'organisation_id' => $this->organisationId,
            'state' => ReportSchedule::STATE_ENABLED,
            'created_by' => self::OWNER,
            'run_count' => 0,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    private function task(): ScheduleReportsTask
    {
        $task = $this->kernel->registries->scheduledTasks->get(ScheduleReportsTask::KEY);

        self::assertInstanceOf(ScheduleReportsTask::class, $task);

        return $task;
    }

    private function seed(): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');

        $this->env->database->insert('courses', [
            'title' => 'Reporting course',
            'slug' => 'scheduled-organisation-audience',
            'state' => 'published',
            'created_by' => self::OWNER,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->kernel->settings->set(ReportExportPipeline::RETENTION_SETTING, 30);

        $this->organisationId = $this->kernel->organisationStore->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'created_by' => self::OWNER,
        ]);

        $scope = $this->kernel->organisationAccess->require(
            Principal::user(self::OWNER),
            $this->organisationId,
            'people',
        );
        // The tenant's own administrator, which is what makes org_admins a
        // question with an answer. Creating an organisation does not seat one:
        // the store writes the organisation row and nothing else.
        $this->kernel->organisationStore->addMember($scope, self::OWNER, 'org_admin', 'manual', null);
        $this->kernel->organisationStore->addMember($scope, self::LEARNER, 'org_learner', 'manual', null);
    }
}
