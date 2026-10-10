<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Reporting;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Report\RowPerPerson;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\AttachesArtefact;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\ReportDeliveryInterface;

/**
 * The two delivery rules that are refusals rather than warnings.
 *
 * **One.** `render_as: author` is refused outright when the resolved audience
 * spans more than one organisation. Rendering once under the author's visibility
 * and mailing one file to twenty organisation administrators is a cross-tenant
 * breach on the first Business site, not a mistake somebody might make; and the
 * feature it looks like a shortcut for, one schedule serving twenty leaders, is
 * exactly what rendering per recipient already does correctly.
 *
 * **Two.** Email-attachment delivery is forbidden for any row-per-person report
 * that crosses an organisation boundary. It is a policy the delivery provider is
 * held to rather than a default a site can flip, because the signed expiring
 * `StoragePort` URL has a property an attachment does not: revoking an
 * administrator's access revokes the report.
 *
 * **The refusals run before the run row opens.** A refusal that fired after the
 * artefact was written would leave a completed run holding personal data that
 * nobody was ever allowed to receive, which is a worse outcome than the delivery
 * it prevented.
 *
 * **A span the policy cannot compute is a span it refuses.** The audience
 * resolver is a collaborator and a caller that has none is a caller that cannot
 * prove one organisation; the safe answer for a rule about cross-tenant
 * disclosure is the refusing one, and neither refusal is reachable on the free
 * build's own paths, which render per recipient through providers that attach
 * nothing.
 */
final class DeliveryBoundaryPolicy
{
    /**
     * @param (\Closure(list<int>): ?int)|null $span how many organisations a
     *                                               recipient set spans, or null
     *                                               when nothing can answer
     */
    private function __construct(private readonly ?\Closure $span)
    {
    }

    /**
     * The policy with a resolver that counts the organisations a recipient set
     * belongs to.
     *
     * @param \Closure(list<int>): ?int $span
     */
    public static function resolvedBy(\Closure $span): self
    {
        return new self($span);
    }

    /** The policy with nothing to ask, which refuses what it cannot prove safe. */
    public static function unresolved(): self
    {
        return new self(null);
    }

    /**
     * @param list<int> $recipientUserIds
     */
    public function requireDeliverable(
        TabularReportInterface $report,
        ?ReportDeliveryInterface $delivery,
        string $renderAs,
        array $recipientUserIds,
        ?int $organisationId,
    ): void {
        $this->requireSingleOrganisationForAuthorRender($renderAs, $recipientUserIds, $organisationId);
        $this->requireNoCrossOrganisationAttachment($report, $delivery, $recipientUserIds, $organisationId);
    }

    /**
     * @param list<int> $recipientUserIds
     */
    private function requireSingleOrganisationForAuthorRender(
        string $renderAs,
        array $recipientUserIds,
        ?int $organisationId,
    ): void {
        if ($renderAs !== \Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext::RENDER_AS_AUTHOR) {
            return;
        }

        if ($this->spans($recipientUserIds, $organisationId) <= 1) {
            return;
        }

        throw ValidationException::forField('render_as', 'A report rendered under its author\'s visibility may be '
            . 'delivered inside one organisation only. This audience spans more than one, so each recipient is '
            . 'served their own artefact under their own scope or nobody is.');
    }

    /**
     * @param list<int> $recipientUserIds
     */
    private function requireNoCrossOrganisationAttachment(
        TabularReportInterface $report,
        ?ReportDeliveryInterface $delivery,
        array $recipientUserIds,
        ?int $organisationId,
    ): void {
        if (!$delivery instanceof AttachesArtefact || !$report instanceof RowPerPerson) {
            return;
        }

        if ($this->spans($recipientUserIds, $organisationId) <= 1) {
            return;
        }

        throw ValidationException::forField('delivery', 'A report with one row per person may not be delivered as an '
            . 'email attachment across an organisation boundary. A signed link expires and can be revoked with the '
            . 'reader\'s access; an attachment in an inbox cannot.');
    }

    /**
     * How many organisations this delivery touches.
     *
     * Two is the answer whenever it cannot be established, which is what makes
     * both rules refusals rather than best efforts. A run anchored to an
     * organisation whose recipients cannot be placed inside it is two by the
     * same rule: a recipient nobody can locate is a recipient who may be
     * anywhere.
     *
     * @param list<int> $recipientUserIds
     */
    private function spans(array $recipientUserIds, ?int $organisationId): int
    {
        if ($recipientUserIds === []) {
            // Nobody but the requester, who is inside the run's own scope.
            return 1;
        }

        if ($this->span === null) {
            return $organisationId !== null || \count($recipientUserIds) > 1 ? 2 : 1;
        }

        $resolved = ($this->span)($recipientUserIds);

        return $resolved === null ? 2 : max(1, $resolved);
    }
}
