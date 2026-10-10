<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Reporting;

use Shondalai\VidyaLMS\Core\Registry\Context\FieldDisclosureContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\FieldDisclosure\FieldDisclosureDecision;
use Shondalai\VidyaLMS\Core\Registry\FieldDisclosure\FieldDisclosureRegistry;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;

/**
 * The one place field disclosure is applied, so the screen and the file cannot
 * disagree about which columns exist.
 *
 * It is the disclosure half of the sentence the suppression policy already
 * carries: a value safe on screen and unsafe in a download is the same
 * disclosure, and disclosure implemented per report is disclosure forgotten in
 * the eleventh one. So a report declares its columns and never decides which of
 * them a reader may have.
 *
 * **It runs before small-cell suppression, not after.** Suppression reasons
 * about the counts in a row and hides the one that would be recoverable by
 * subtraction, so it has to see the row a reader will actually get. Running it
 * first and then removing a column would leave a complementary suppression
 * computed over a cell nobody receives.
 *
 * **A site-scoped run is untouched.** Disclosure is a per-organisation policy
 * because the organisation is the controller; a report with no organisation
 * scope has no controller to read a policy from, and inventing a site-wide
 * default here would apply an organisation's decision to a site manager's own
 * report of their own site.
 */
final class DisclosurePipeline
{
    public function __construct(private readonly FieldDisclosureRegistry $policies)
    {
    }

    /**
     * The pipeline a caller constructed without one, which discloses nothing
     * inside an organisation.
     *
     * The empty registry is the point rather than a convenience: an absent
     * disclosure stage on an organisation-scoped run is exactly the state the
     * published miss direction is written for, and the direction is that the
     * field is omitted.
     */
    public static function failClosed(): self
    {
        return new self(new FieldDisclosureRegistry());
    }

    /**
     * The columns this reader may have, in the order the report declared them.
     *
     * @param list<ColumnDefinition> $columns
     *
     * @return list<ColumnDefinition>
     */
    public function columnsFor(array $columns, ReportContext $context): array
    {
        if ($context->orgScope === null) {
            return $columns;
        }

        $kept = [];

        foreach ($columns as $column) {
            if ($this->decide($column->key, $context) !== FieldDisclosureDecision::OMIT) {
                $kept[] = $column;
            }
        }

        return $kept;
    }

    /**
     * The keys a run omitted, so a reader is told a column was withheld rather
     * than left to wonder whether the report has one.
     *
     * @param list<ColumnDefinition> $columns
     *
     * @return list<string>
     */
    public function omitted(array $columns, ReportContext $context): array
    {
        if ($context->orgScope === null) {
            return [];
        }

        $omitted = [];

        foreach ($columns as $column) {
            if ($this->decide($column->key, $context) === FieldDisclosureDecision::OMIT) {
                $omitted[] = $column->key;
            }
        }

        return $omitted;
    }

    /**
     * One row reduced to the disclosed columns, with a redacted cell emptied.
     *
     * A key the report yielded that no column declares is dropped, because a
     * column nobody declared is a column no policy was asked about, and an
     * undeclared key reaching a CSV is how a field arrives in an export without
     * ever passing this stage.
     *
     * @param array<string, mixed>   $row
     * @param list<ColumnDefinition> $disclosed the columns {@see columnsFor()} kept
     *
     * @return array<string, mixed>
     */
    public function apply(array $row, array $disclosed, ReportContext $context): array
    {
        if ($context->orgScope === null) {
            return $row;
        }

        $out = [];

        foreach ($disclosed as $column) {
            if (!\array_key_exists($column->key, $row)) {
                continue;
            }

            $out[$column->key] = $this->decide($column->key, $context) === FieldDisclosureDecision::REDACT
                ? FieldDisclosureDecision::REDACTION
                : $row[$column->key];
        }

        return $out;
    }

    /**
     * The same rule over the scalar half of a report.
     *
     * A summary block is exactly as disclosing as a row, which is the argument
     * {@see \Shondalai\VidyaLMS\Core\Domain\Reporting\CellSuppressionPolicy::applyToFacts()}
     * already makes for suppression, applied to the other policy in the pair.
     *
     * @param array<string, mixed> $facts
     *
     * @return array{0: array<string, mixed>, 1: list<string>} the facts and what was omitted
     */
    public function applyToFacts(array $facts, ReportContext $context): array
    {
        if ($context->orgScope === null) {
            return [$facts, []];
        }

        $omitted = [];

        foreach (array_keys($facts) as $key) {
            $decision = $this->decide((string) $key, $context);

            if ($decision === FieldDisclosureDecision::OMIT) {
                unset($facts[$key]);
                $omitted[] = (string) $key;
            } elseif ($decision === FieldDisclosureDecision::REDACT) {
                $facts[$key] = FieldDisclosureDecision::REDACTION;
            }
        }

        return [$facts, $omitted];
    }

    /**
     * The decision for one field under the organisation's own policy key.
     *
     * The key comes from the resolved scope and never from a parameter, for the
     * reason the scope's own docblock gives: it is a digest input, so a reader
     * allowed more columns and a reader allowed fewer do not share an artefact.
     */
    private function decide(string $field, ReportContext $context): string
    {
        $scope = $context->orgScope;

        if ($scope === null) {
            return FieldDisclosureDecision::ALLOW;
        }

        return $this->policies->decideThrough(
            $scope->disclosureKey,
            FieldDisclosureContext::of($scope, $field),
        );
    }
}
