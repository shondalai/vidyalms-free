<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Importer\AbstractImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceSchemes;
use Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceTypes;
use Shondalai\VidyaLMS\Core\Registry\Importer\HonoursUpsertPolicy;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;
use Shondalai\VidyaLMS\Core\Registry\Importer\UpsertPolicy;

/**
 * What the three Joomla source importers have in common: the source
 * convention, the declared options, the run's opening statement and its
 * closing summary.
 *
 * Shika, Guru and OSCampus are three Joomla LMS components that keep their
 * tables in the same database as VidyaLMS, so all three are database imports
 * rather than file ones. They read through DatabasePort as host tables, they
 * write through CurriculumWriter and therefore through the same use cases the
 * authoring UI calls, and they refuse the same way when the product is not
 * installed.
 *
 * ## All three are unverified against a real install
 *
 * None of these three products is installed on either development site, and
 * this wave installed none of them. Each importer was written from its
 * product's public schema documentation and, where the product is open
 * source, from its own source. That fact is not a footnote: it is on the
 * label, in the class docblock, and in the first line of every run's log,
 * because the difference between a migration path that has been run against
 * the real thing and one that has not is the difference between a promise and
 * an estimate.
 *
 * ## The source
 *
 *   cms-db:                  every course of the product
 *   cms-db:courses=12,15     those courses
 *
 * supports() answers on the scheme AND on the product being there: an
 * OSCampus importer on a site with no `oscampus_courses` table says no, so
 * the Import screen offers the migration paths the site can actually take
 * rather than all six of them.
 */
abstract class JoomlaSourceImporter extends AbstractImporter implements
    DeclaresSourceSchemes,
    DeclaresSourceTypes,
    HonoursUpsertPolicy
{
    /** The source scheme all three answer to. */
    public const SCHEME = 'cms-db:';

    public const OPTION_CONTENT = 'content';

    /** Everything the importer can carry, which is the default. */
    public const CONTENT_WITH_QUIZZES = 'curriculum_and_quizzes';

    /** Courses, modules and lessons, without the quizzes hanging off them. */
    public const CONTENT_CURRICULUM = 'curriculum_only';

    /**
     * The learner option is spelled `enrolments` rather than `learners`,
     * because that is the word the three shipped importers and the three
     * WordPress importers of this wave already use for the same choice, and
     * one option name across six importers is worth more than a better word
     * in one of them.
     */
    public const OPTION_LEARNERS = 'enrolments';

    public const OPTION_PROGRESS = 'progress';

    public const OPTION_PUBLISH = 'publish';

    public function __construct(
        protected readonly CurriculumWriter $writer,
    ) {
    }

    /**
     * The options every one of these importers declares.
     *
     * `dry_run` is deliberately absent: the pipeline owns it, ImportOption
     * refuses it by name, and it is decided by which button the operator
     * pressed rather than by a control an importer draws.
     *
     * @return list<ImportOption>
     */
    public function importOptions(): array
    {
        $translator = $this->translator();

        return [
            ImportOption::choice(
                self::OPTION_CONTENT,
                $translator->translate('vidyalms.importer.option.content'),
                [
                    [
                        'value' => self::CONTENT_WITH_QUIZZES,
                        'label' => $translator->translate('vidyalms.importer.option.content.curriculum_and_quizzes'),
                    ],
                    [
                        'value' => self::CONTENT_CURRICULUM,
                        'label' => $translator->translate('vidyalms.importer.option.content.curriculum_only'),
                    ],
                ],
                $translator->translate('vidyalms.importer.option.content.help'),
                self::CONTENT_WITH_QUIZZES,
            ),
            ImportOption::boolean(
                self::OPTION_LEARNERS,
                $translator->translate('vidyalms.importer.option.enrolments'),
                $translator->translate('vidyalms.importer.option.enrolments.help'),
            ),
            ImportOption::boolean(
                self::OPTION_PROGRESS,
                $translator->translate('vidyalms.importer.option.progress'),
                $translator->translate('vidyalms.importer.option.progress.help'),
            ),
            ImportOption::boolean(
                self::OPTION_PUBLISH,
                $translator->translate('vidyalms.importer.option.publish'),
                $translator->translate('vidyalms.importer.option.publish.help'),
            ),
            UpsertPolicy::option(
                $translator->translate('vidyalms.importer.option.upsert'),
                [
                    UpsertPolicy::CREATE_ONLY =>
                        $translator->translate('vidyalms.importer.option.upsert.create_only'),
                    UpsertPolicy::CREATE_AND_UPDATE =>
                        $translator->translate('vidyalms.importer.option.upsert.create_and_update'),
                    UpsertPolicy::UPDATE_ONLY =>
                        $translator->translate('vidyalms.importer.option.upsert.update_only'),
                    UpsertPolicy::CREATE_WITH_SUFFIX =>
                        $translator->translate('vidyalms.importer.option.upsert.create_with_suffix'),
                ],
                $translator->translate('vidyalms.importer.option.upsert.help'),
            ),
        ];
    }

    /**
     * All four, and each of them really. A course, a module, a lesson, a quiz
     * and a question all carry the product's own permanent id, so
     * create_with_suffix has a real second copy to make and update_only has a
     * real row to find.
     *
     * @return list<string>
     */
    public function upsertPolicies(): array
    {
        return UpsertPolicy::POLICIES;
    }

    /**
     * The source_type words these importers pass to the import map. None is
     * one of the reserved organisation words, so none carries a tenant
     * prefix: a Guru course belongs to the site rather than to an
     * organisation.
     *
     * @return list<string>
     */
    public function sourceTypes(): array
    {
        return CurriculumWriter::SOURCE_TYPES;
    }

    final public function importWithProgress(
        string $sourcePath,
        array $options,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        if (!$this->claimsScheme($sourcePath)) {
            throw new \RuntimeException(sprintf(
                '"%s" is not a %s source. Expected "%s", optionally with a selector such as "%scourses=12,15".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $sourcePath,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->productName(),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::SCHEME,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::SCHEME,
            ));
        }

        $run = new SourceRun(
            $principal,
            $progress,
            UpsertPolicy::of($options),
            learners: ($options[self::OPTION_LEARNERS] ?? false) === true,
            completions: ($options[self::OPTION_PROGRESS] ?? false) === true,
            publish: ($options[self::OPTION_PUBLISH] ?? false) === true,
            quizzes: ($options[self::OPTION_CONTENT] ?? self::CONTENT_WITH_QUIZZES) !== self::CONTENT_CURRICULUM,
        );

        $run->warn($this->unverifiedNote());

        if (!$this->installed()) {
            // A refusal rather than an empty success. "Nothing to import" and
            // "the product is not here" are different facts, and an operator
            // who reads the first when the second is true goes looking for
            // their courses in the wrong place.
            throw new \RuntimeException(sprintf(
                '%s does not look installed on this site: the "%s" table could not be read. Run this'
                    . ' importer on the site whose database holds the %s tables.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->productName(),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->markerTable(),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->productName(),
            ));
        }

        $this->importCourses($run, $this->selector($sourcePath));
        $this->writer->summarise($run);
    }

    /**
     * Whether this string is one of these importers' sources at all, before
     * anything has been read.
     */
    final protected function claimsScheme(string $sourcePath): bool
    {
        return str_starts_with(strtolower(trim($sourcePath)), self::SCHEME);
    }

    /**
     * The scheme this importer answers to, said out loud for the screens and
     * for the published catalogue. Both hosts probed this literal, which was
     * right for these importers and wrong for the three that read another
     * product's tables; declaring it makes the assumption a contract.
     *
     * @return list<string>
     */
    public function sourceSchemes(): array
    {
        return [self::SCHEME];
    }

    /**
     * Reads the selector after the scheme. An unreadable one fails the whole
     * import on purpose: importing every course on the site because a filter
     * was misspelled is worse than being told.
     *
     * @return list<int>
     */
    final protected function selector(string $sourcePath): array
    {
        $selector = trim(substr(trim($sourcePath), \strlen(self::SCHEME)));

        if ($selector === '') {
            return [];
        }

        $parts = explode('=', $selector, 2);

        if (strtolower(trim($parts[0])) !== 'courses') {
            throw new \RuntimeException(sprintf(
                'Unknown %s selector "%s". Use "courses".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->productName(),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                trim($parts[0]),
            ));
        }

        $ids = [];

        foreach (explode(',', $parts[1] ?? '') as $value) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \RuntimeException(sprintf('"%s" is not a %s course id.', $value, $this->productName()));
            }

            $ids[] = (int) $value;
        }

        if ($ids === []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('"%s" names no course ids.', $selector));
        }

        return array_values(array_unique($ids));
    }

    /**
     * The translator this importer's own labels go through.
     */
    abstract protected function translator(): TranslatorPort;

    /**
     * The product's name as a person calls it, for every sentence about it.
     */
    abstract protected function productName(): string;

    /**
     * The one table whose presence says the product is installed here.
     */
    abstract protected function markerTable(): string;

    /**
     * Whether the product is installed on the host database.
     */
    abstract protected function installed(): bool;

    /**
     * The sentence every run of this importer opens with: that it was written
     * from documentation, what documentation, and what a site owner should
     * check first.
     */
    abstract protected function unverifiedNote(): string;

    /**
     * Reads the product's courses and hands each one to the writer.
     *
     * @param list<int> $courseIds the selector's courses, empty for all of them
     */
    abstract protected function importCourses(SourceRun $run, array $courseIds): void;
}
