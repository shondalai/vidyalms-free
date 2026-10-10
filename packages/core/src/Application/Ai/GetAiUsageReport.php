<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Provider\Report\AiUsageReport;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportProviderInterface;

/**
 * The usage report, resolved through the report registry rather than computed
 * here.
 *
 * The route exists because the design gives the report its own screen and its
 * own capability, following Moodle's separate viewaiusagereport; the
 * computation lives in a registered provider because a site wanting a different
 * cut of the same rows should register a report rather than wait for a route.
 * That is why this class is thin: it resolves the provider, asks it for its own
 * required capability, and hands it a context.
 *
 * The policy version and the acceptance count are joined on afterwards. The
 * report reads runs and drafts; the number a site owner needs the day after a
 * policy change is a different table, and a report provider that reached into
 * it would be answering a question it was not asked.
 */
final class GetAiUsageReport
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly Registries $registries,
        private readonly AccessGuard $guard,
        private readonly SqlAiReadModel $reads,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Principal $principal, ?string $from, ?string $to, ?int $organisationId): array
    {
        $provider = $this->registries->reports->get(AiUsageReport::KEY);

        if (!$provider instanceof ReportProviderInterface) {
            throw new \LogicException('The AI usage report is a free provider and must be registered.');
        }

        // The provider declares what a caller must hold, so narrowing it later
        // is a provider edit rather than a route edit.
        $this->guard->require($principal, $provider->requiredCapability());

        $report = $provider->generate(ReportContext::create(
            $organisationId === null ? [] : ['organisation_id' => $organisationId],
            $principal,
            null,
            null,
            null,
            self::instant($from, 'from'),
            self::instant($to, 'to'),
        ));

        $active = $this->reads->activePolicy($this->clock->now()->format(self::DATETIME_FORMAT));

        $report['policy_version'] = $active === null ? null : (int) $active['version'];
        $report['acceptance_count'] = $active === null ? 0 : $this->reads->acceptanceCount((int) $active['version']);

        return $report;
    }

    private static function instant(?string $value, string $field): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'must be a UTC instant such as 2026-09-01 00:00:00');
        }
    }
}
