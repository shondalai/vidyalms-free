<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Importer\ConfigurableImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterRegistry;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption;
use Shondalai\VidyaLMS\Core\Registry\Shape\DeclaresParamsShape;

/**
 * The checks every way into the import pipeline shares, before a job row
 * exists: the importer key must be one this build registered, and the options
 * must be stored in a shape the runner can trust.
 *
 * Refusing an unknown key here rather than at run time matters: a job created
 * for an importer nobody registered would sit pending forever, and the admin
 * would have no idea why. The refusal names what is registered, because the
 * usual cause is an extension that failed to install.
 */
final class ImportIntake
{
    public function __construct(
        private readonly ImporterRegistry $importers,
    ) {
    }

    public function importer(string $importerKey): ImporterInterface
    {
        $importerKey = trim($importerKey);

        if ($importerKey === '') {
            throw ValidationException::forField('importer', 'An importer key is required.');
        }

        if (!$this->importers->has($importerKey)) {
            $registered = $this->importers->keys();

            throw ValidationException::forField('importer', sprintf(
                'Unknown importer "%s". Registered importers: %s.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $importerKey,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $registered === [] ? 'none' : implode(', ', $registered),
            ));
        }

        return $this->importers->get($importerKey);
    }

    /** Only an actor's import uploads or an importer's declared source scheme. */
    public function source(?string $path, int $actorId, ImporterInterface $importer): void
    {
        if ($path === null || $path === '') {
            return;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1) {
            if ($importer->supports($path)) {
                return;
            }
        } elseif ($actorId > 0 && str_starts_with($path, 'imports/' . $actorId . '/')) {
            try {
                \Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety::downloadable($path);
                return;
            } catch (\InvalidArgumentException) {
                // Report invalid upload references as input errors, not server failures.
            }
        }
        throw ValidationException::forField('source', 'Choose an import file uploaded by your account.');
    }

    /**
     * Stores dry_run as a real boolean, so the runner's "is this a preview?"
     * test cannot be fooled by a 1 that arrived from a form post, and coerces
     * every option the importer declared through the importer's own
     * declaration.
     *
     * The declared half is what was missing. ImportOption::read() exists so
     * that "the same form on two CMSes cannot mean two things", and it was
     * called from exactly two places: the Joomla screen and the WordPress
     * screen. Everything arriving over the API skipped it, so a POST could
     * send `upsert_policy: "delete_everything"`, which is not one of the four
     * declared values, or `skip_unknown_roles: "no"`, which every importer
     * reads as true because a non-empty string is not false, and both reached
     * import() verbatim. Running it here puts every entry point, screen or
     * API, through one coercion.
     *
     * The importer is optional so the two callers that resolve it can pass it
     * and nothing that does not is broken; an unknown importer coerces nothing
     * beyond dry_run, which is what happened everywhere before.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function options(array $options, ?ImporterInterface $importer = null): array
    {
        // Read before the coercion, because ImportOption::read() drops the
        // reserved key by name: the pipeline owns dry_run and an importer may
        // not declare a control for it, so read() would answer without it and
        // every preview would run for real.
        /** @var mixed $dryRun */
        $dryRun = $options['dry_run'] ?? false;

        if ($importer instanceof ConfigurableImporterInterface) {
            try {
                $declared = $importer->importOptions();
            } catch (\Throwable) {
                // A third-party importer whose declaration throws must not be
                // able to stop an import that names it, which is the rule
                // both host screens already apply: it is read as declaring
                // nothing rather than as a failure.
                $declared = [];
            }

            if ($declared !== []) {
                $options = ImportOption::read($declared, $options);
            }
        }

        $options['dry_run'] = $dryRun === true || $dryRun === 1 || $dryRun === '1' || $dryRun === 'true';

        return $options;
    }

    /**
     * The shape this importer's options are written in today.
     *
     * import_jobs.options is provider-owned JSON: ImportOption's own docblock
     * says an option's meaning "belongs to the importer that declares them",
     * and RunQueuedImport reads the map back at drain time, which may be days
     * later and after a plugin upgrade. That is exactly the reinterpretation
     * StoredShape was written for, and the column carried no version at all,
     * so a replaced importer read a queued job's options as though it had
     * written them.
     *
     * Probed with instanceof through the interface the seat providers already
     * use, so an importer that declares nothing writes shape 1, which is what
     * a first shape is.
     */
    public function shapeOf(ImporterInterface $importer): int
    {
        return $importer instanceof DeclaresParamsShape ? max(1, $importer->currentShape()) : 1;
    }
}
