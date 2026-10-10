<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

defined('_JEXEC') or die;

use Shondalai\VidyaLMS\Core\Registry\Importer\ConfigurableImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceSchemes;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterRegistry;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption;

/**
 * What the Import screen needs to know about a registered importer, asked of
 * the importer itself through the only method the contract gives us:
 * supports().
 *
 * The source convention has two shapes, a private storage path and a scheme
 * URI, and the screen has to offer the right control for each.
 *
 * **What an importer accepts is its declaration; whether its product is on
 * this site is a separate question.** Both used to be answered by probing
 * supports(), which asks them at once, and the answers contradicted the
 * contract and each other: on a Joomla site the three WordPress importers were
 * described as accepting files, offered the eleven candidate extensions, and
 * answered 303 to anybody who tried to start them, while
 * `GET /import/importers` described the same importers as `learndash-db:` with
 * `accepts_file` false. `ListImporters` states the rule the contract follows in
 * its own docblock, "the list is deliberately not a health check", and this
 * screen now follows the same one and adds the health check beside it as
 * `source_installed`.
 *
 * - The database scheme is what the importer declares through
 *   `DeclaresSourceSchemes`, or the historical "cms-db:" probe for one that
 *   shipped before the interface.
 * - The upload's extension allowlist is the subset of CANDIDATES the importer
 *   recognises on a probe path. CANDIDATES is the hard cap and is never
 *   widened by an importer: an importer that claimed to accept ".php" would
 *   still not be offered it.
 *
 * An importer that recognises nothing is read two ways. One that also answers
 * to "cms-db:" is a database importer and is offered no upload at all; one that
 * answers to neither is a file importer with a strict detector (its supports()
 * reads magic bytes, and a probe path has none), so it falls back to the whole
 * candidate list. A strict detector therefore costs the operator a clearer
 * error message, never the ability to upload.
 *
 * @since 1.0.0
 */
final class ImportSources
{
    /**
     * The scheme that means "the tables of this site's own database", per the
     * core's source convention.
     */
    public const DATABASE_SOURCE = 'cms-db:';

    /**
     * Uploads land under this folder in private storage, one subfolder per
     * uploading user.
     */
    public const UPLOAD_FOLDER = 'imports';

    /**
     * Every extension the component will ever accept as an import upload.
     * Nothing outside this list reaches the filesystem, whatever an importer
     * claims to support.
     *
     * @var list<string>
     */
    public const CANDIDATES = [
        'json',
        'zip',
        'mbz',
        'xml',
        'csv',
        'tsv',
        'txt',
        'gift',
        'sql',
        'tar',
        'gz',
        'tgz',
        'bz2',
        'xz',
    ];

    private function __construct()
    {
    }

    /**
     * Every registered importer, in registration order, described for the
     * screen. The screen offers exactly this list, so an importer whose key is
     * not registered can never be chosen.
     *
     * @return list<array{key: string, label: string, extensions: list<string>, accepts_file: bool, accepts_database: bool, database_scheme: string, source_installed: bool, options: list<array{key: string, label: string, kind: string, help: string, default: string, choices: list<array{value: string, label: string}>}>}>
     */
    public static function descriptors(ImporterRegistry $importers): array
    {
        $descriptors = [];

        foreach ($importers->all() as $importer) {
            if (!\Shondalai\VidyaLMS\Core\Application\Platform\ImportReadiness::listed($importer)) {
                continue;
            }
            $descriptors[] = self::describe($importer);
        }

        return $descriptors;
    }

    /**
     * @return array{key: string, label: string, extensions: list<string>, accepts_file: bool, accepts_database: bool, database_scheme: string, source_installed: bool, options: list<array{key: string, label: string, kind: string, help: string, default: string, choices: list<array{value: string, label: string}>}>}
     */
    public static function describe(ImporterInterface $importer): array
    {
        $recognised = self::recognisedExtensions($importer);
        $scheme     = self::databaseScheme($importer);

        // An importer that recognises no file at all but does answer to a
        // database scheme is a database importer, and offering it an upload
        // control would only invite a file it is going to refuse. Everything
        // else is offered the upload, including a strict detector that
        // recognised nothing on a probe path.
        return [
            'key'              => $importer->key(),
            'label'            => self::label($importer),
            'extensions'       => $recognised !== [] ? $recognised : self::CANDIDATES,
            'accepts_file'     => $recognised !== [] || $scheme === '',
            'accepts_database' => $scheme !== '',
            'database_scheme'  => $scheme,
            // Whether the product is on this site, which is the other half of
            // what the probe used to answer. A screen that hid a database
            // importer because its product was absent described the importer
            // wrongly; a screen that says "LearnDash is not on this site"
            // describes the site.
            'source_installed' => self::sourceInstalled($importer),
            'options'          => self::options($importer),
        ];
    }

    /**
     * What this importer says it accepts beyond a source, ready for the
     * screen to draw a control for each.
     *
     * The declaration is the importer's (ConfigurableImporterInterface); this
     * screen holds no table of option names and never grows one. An importer
     * that declares nothing gets the source controls and nothing else, which
     * is what every importer got before the seam existed, and one whose
     * declaration throws is read as declaring nothing rather than taking the
     * screen down: a third-party importer must not be able to stop a Moodle
     * migration.
     *
     * @return list<array{key: string, label: string, kind: string, help: string, default: string, choices: list<array{value: string, label: string}>}>
     */
    public static function options(ImporterInterface $importer): array
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
        $seen    = [];

        foreach ($declared as $option) {
            if (!$option instanceof ImportOption || \in_array($option->key, $seen, true)) {
                continue;
            }

            $seen[]    = $option->key;
            $options[] = $option->toArray();
        }

        return $options;
    }

    /**
     * A label that never comes back empty: an importer whose translation key
     * is missing from the catalogue would otherwise render as a blank option.
     */
    public static function label(ImporterInterface $importer): string
    {
        try {
            $label = trim($importer->label());
        } catch (\Throwable) {
            $label = '';
        }

        return $label !== '' ? $label : $importer->key();
    }

    /**
     * The candidate extensions this importer actually claims, empty when it
     * claims none. describe() is what turns an empty answer into either the
     * whole candidate list or a database-only importer.
     *
     * @return list<string>
     */
    public static function recognisedExtensions(ImporterInterface $importer): array
    {
        $accepted = [];

        foreach (self::CANDIDATES as $extension) {
            if (self::probe($importer, self::UPLOAD_FOLDER . '/probe.' . $extension)) {
                $accepted[] = $extension;
            }
        }

        return $accepted;
    }

    public static function acceptsDatabase(ImporterInterface $importer): bool
    {
        return self::databaseScheme($importer) !== '';
    }

    /**
     * The database scheme this importer actually answers to, or '' when it
     * answers to none.
     *
     * This used to probe one literal, self::DATABASE_SOURCE, and that was the
     * whole of the question until R4 registered three importers reading
     * another product's tables. `learndash-db:`, `tutor-db:` and
     * `lifterlms-db:` all answer false to `cms-db:` on purpose, because a
     * LearnDash importer must not claim the Community Quiz importer's source
     * string, so all three were described as file importers, offered the
     * eleven candidate extensions, and could not be started from this screen
     * at all. Asking the importer which schemes it answers to, and probing
     * those, is the seam; the literal stays as the default for the importers
     * that shipped before the interface and never declared.
     */
    public static function databaseScheme(ImporterInterface $importer): string
    {
        // **Declared, not probed, for an importer that declares.**
        //
        // Probing every scheme and answering '' when none was accepted folded
        // two different questions into one field. supports() asks "is this a
        // source I understand AND is that product on this site", so on a
        // Joomla site the three WordPress importers answered no, the screen
        // reported accepts_database false and, because the scheme came back
        // empty, accepts_file TRUE: a database-only importer described as a
        // file importer, offered the eleven candidate extensions, and refusing
        // whatever was uploaded. `ListImporters` serves the same catalogue over
        // the contract and says the opposite in its own docblock: "the list is
        // deliberately not a health check". The contract was right and both
        // screens disagreed with it and with each other.
        //
        // So the declaration answers what the importer accepts, and
        // sourceInstalled() answers separately whether the product is here. A
        // screen shows both; it never turns the second into the first.
        if ($importer instanceof DeclaresSourceSchemes) {
            $declared = self::declaredSchemes($importer);

            return $declared === [] ? '' : $declared[0];
        }

        // An importer that predates the interface is still probed against the
        // historical literal, because nothing else distinguishes a legacy
        // database importer from a file one.
        return self::probe($importer, self::DATABASE_SOURCE) ? self::DATABASE_SOURCE : '';
    }

    /**
     * Whether the product this importer reads is on this site.
     *
     * The second half of the question databaseScheme() used to answer on its
     * own. It is a separate fact because it has a separate fix: an importer
     * that accepts a database and whose product is absent is not a file
     * importer, it is an importer with nothing to read yet.
     */
    public static function sourceInstalled(ImporterInterface $importer): bool
    {
        $scheme = self::databaseScheme($importer);

        return $scheme !== '' && self::probe($importer, $scheme);
    }

    /**
     * The schemes to probe: the importer's own declaration, or the historical
     * default for one that declares none.
     *
     * @return list<string>
     */
    private static function declaredSchemes(ImporterInterface $importer): array
    {
        if (!$importer instanceof DeclaresSourceSchemes) {
            return [self::DATABASE_SOURCE];
        }

        try {
            $declared = $importer->sourceSchemes();
        } catch (\Throwable $failure) {
            // A third-party importer whose declaration throws must not take
            // the screen down, which is the rule this class already applies to
            // label() and importOptions().
            return [self::DATABASE_SOURCE];
        }

        $schemes = [];

        foreach ($declared as $scheme) {
            $scheme = strtolower(trim($scheme));

            if ($scheme !== '' && preg_match('/^[a-z][a-z0-9+.-]*:$/', $scheme) === 1) {
                $schemes[] = $scheme;
            }
        }

        return $schemes === [] ? [self::DATABASE_SOURCE] : $schemes;
    }

    /**
     * The database source with the operator's selector appended, per the
     * convention that a selector may follow the colon ("cms-db:categories=3,7").
     * The selector's meaning belongs to the importer that declares it, so it is
     * only stripped of whitespace and control characters here.
     *
     * The scheme is the one the descriptor reported for the chosen importer,
     * so a LearnDash run composes "learndash-db:..." rather than a string
     * LearnDash refuses.
     */
    public static function databaseSource(string $selector, string $scheme = self::DATABASE_SOURCE): string
    {
        $scheme = strtolower(trim($scheme));

        if (preg_match('/^[a-z][a-z0-9+.-]*:$/', $scheme) !== 1) {
            $scheme = self::DATABASE_SOURCE;
        }

        $selector = trim($selector);

        if ($selector === '') {
            return $scheme;
        }

        $selector = ltrim($selector, ':');
        $selector = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $selector);

        return $scheme . trim($selector);
    }

    /**
     * The lower-cased extension of an uploaded file name, without the dot.
     */
    public static function extensionOf(string $fileName): string
    {
        $extension = pathinfo($fileName, \PATHINFO_EXTENSION);

        return strtolower(\is_string($extension) ? $extension : '');
    }

    /**
     * A file name reduced to what is safe as one storage path segment. The
     * extension is passed in already validated against the allowlist, so a
     * double extension in the uploaded name cannot survive.
     */
    public static function safeFileName(string $fileName, string $extension): string
    {
        $base = pathinfo($fileName, \PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', \is_string($base) ? $base : '');
        $base = trim($base, '-.');

        if ($base === '') {
            $base = 'source';
        }

        return substr($base, 0, 60) . '.' . $extension;
    }

    /**
     * supports() is detection, not a gate, and a third-party importer may read
     * bytes in it. A probe that throws is answered "no" rather than taken as a
     * reason to fail the screen.
     */
    private static function probe(ImporterInterface $importer, string $source): bool
    {
        try {
            return $importer->supports($source);
        } catch (\Throwable) {
            return false;
        }
    }
}
