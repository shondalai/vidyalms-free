<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\Importer\ConfigurableImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceSchemes;
use Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceTypes;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterRegistry;
use Shondalai\VidyaLMS\Core\Registry\Importer\RunsForAnOrganisation;

/**
 * The importer catalogue, published.
 *
 * ## Why a route exists for this
 *
 * Nothing published it, so both host adapters read the registry in process and
 * each grew its own answer to the same four questions: which importers are
 * registered, what each is called, which source shapes it takes, and which
 * options it declares. The two ImportSources classes came to 272 and 300 lines
 * of identical constants and identical methods, and the two Import screens to
 * 437 and 1118 lines with mirrored source resolution, upload storage and poll
 * payloads. A third host adapter rewrote about seven hundred lines of that
 * before it drew a control, and a headless client could not discover an
 * importer or its options at all.
 *
 * So the catalogue is served once, from the registry, and a screen on any host
 * and any client outside one read the same contract.
 *
 * ## What each field is for
 *
 * - `key` and `label` are what a picker draws.
 * - `options` is `ImportOption::toArray()` verbatim, which is the declaration
 *   a host draws a control from and the vocabulary `ImportOption::read()`
 *   coerces against.
 * - `schemes` is what a database control composes onto, and it is the
 *   importer's own answer rather than the literal `cms-db:` two screens used
 *   to assume. It is the fix for three importers that could not be started at
 *   all.
 * - `accepts_file` says whether an upload is worth offering.
 * - `source_types` is what a run will touch, which is what a screen shows
 *   before an operator commits and what a retention sweep reads.
 * - `organisation_eligible` says whether an organisation administrator may
 *   start it from their own console, which is the same declaration
 *   OrganisationImports enforces.
 *
 * The list is deliberately not a health check. Whether the product an importer
 * reads is installed is `supports()`, which takes a source and belongs to the
 * screen that is about to compose one; asking it here would make a catalogue
 * read run nine database probes.
 */
final class ListImporters
{
    public function __construct(
        private readonly ImporterRegistry $importers,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     schemes: list<string>,
     *     accepts_file: bool,
     *     source_types: list<string>,
     *     organisation_eligible: bool,
     *     options: list<array<string, mixed>>
     * }>
     */
    public function execute(Principal $principal): array
    {
        $this->guard->require($principal, Capability::MANAGE);

        $catalogue = [];

        foreach ($this->importers->all() as $importer) {
            if (!ImportReadiness::listed($importer)) {
                continue;
            }
            $schemes = self::schemes($importer);
            $catalogue[] = [
                'key' => $importer->key(),
                'label' => self::label($importer),
                'schemes' => $schemes,
                // An importer that answers to a scheme reads a database, so an
                // upload control would only invite a file it is going to
                // refuse. This is the same rule both host screens already
                // apply, said once here instead of twice there.
                'accepts_file' => $schemes === [],
                'source_types' => self::sourceTypes($importer),
                'organisation_eligible' => $importer instanceof RunsForAnOrganisation,
                'options' => self::options($importer),
            ];
        }

        return $catalogue;
    }

    /**
     * A label that never comes back empty, because a blank option in a picker
     * is worse than an untranslated key.
     */
    private static function label(ImporterInterface $importer): string
    {
        try {
            $label = trim($importer->label());
        } catch (\Throwable) {
            $label = '';
        }

        return $label !== '' ? $label : $importer->key();
    }

    /**
     * @return list<string>
     */
    private static function schemes(ImporterInterface $importer): array
    {
        if (!$importer instanceof DeclaresSourceSchemes) {
            return [];
        }

        try {
            $declared = $importer->sourceSchemes();
        } catch (\Throwable) {
            // A third-party importer whose declaration throws must not take
            // the catalogue down, which is the rule both host screens already
            // apply to a label and an option list.
            return [];
        }

        $schemes = [];

        foreach ($declared as $scheme) {
            $scheme = strtolower(trim($scheme));

            if ($scheme !== '' && preg_match('/^[a-z][a-z0-9+.-]*:$/', $scheme) === 1) {
                $schemes[] = $scheme;
            }
        }

        return $schemes;
    }

    /**
     * @return list<string>
     */
    private static function sourceTypes(ImporterInterface $importer): array
    {
        if (!$importer instanceof DeclaresSourceTypes) {
            return [];
        }

        try {
            $types = $importer->sourceTypes();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter($types, static fn (string $type): bool => trim($type) !== ''));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function options(ImporterInterface $importer): array
    {
        if (!$importer instanceof ConfigurableImporterInterface) {
            return [];
        }

        try {
            $declared = $importer->importOptions();
        } catch (\Throwable) {
            return [];
        }

        $options = [];
        $seen = [];

        foreach ($declared as $option) {
            if (!$option instanceof ImportOption || \in_array($option->key, $seen, true)) {
                continue;
            }

            $seen[] = $option->key;
            $options[] = $option->toArray();
        }

        return $options;
    }
}
