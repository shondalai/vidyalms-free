<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\Moodle;

use Shondalai\VidyaLMS\Core\Infrastructure\Archive\TarArchive;
use Shondalai\VidyaLMS\Core\Infrastructure\Xml\XmlNode;

/**
 * One .mbz understood: the manifest, the course, the sections in order, the
 * activities under each of them, the file table, the question bank and the
 * gradebook.
 *
 * Everything here is reading. Nothing in this class writes, which is what
 * lets the importer's dry run take exactly the same path as a real one.
 *
 * ## The layout it reads
 *
 *   moodle_backup.xml                    manifest: sections, activities, origin
 *   course/course.xml                    fullname, shortname, summary, lang
 *   sections/section_<id>/section.xml    name, summary, sequence of cmids
 *   activities/<mod>_<cmid>/<mod>.xml    the activity itself
 *   activities/<mod>_<cmid>/module.xml   the course module record
 *   files.xml                            every file, by context and area
 *   files/<xx>/<contenthash>             the bytes
 *   questions.xml                        the question bank
 *   gradebook.xml                        grade items, which carry the pass mark
 *
 * Two question bank shapes exist and both are read: the Moodle 3.x
 * category/questions nesting, and the 4.x bank entry with versions. A quiz
 * slot names either a question id or a bank entry id depending on which
 * Moodle wrote the archive.
 */
final class MoodleBackup
{
    private const MANIFEST = 'moodle_backup.xml';

    /**
     * @param array<string, list<array{hash: string, name: string, size: int}>> $files       context, component and area to files
     * @param array<string, XmlNode>                                           $questions   Moodle question id to question
     * @param array<string, XmlNode>                                           $bankEntries bank entry id to its newest question
     * @param array<string, array{grademax: float, gradepass: float}>          $gradeItems  module and instance to its grade item
     */
    private function __construct(
        private readonly TarArchive $archive,
        private readonly XmlNode $information,
        private readonly ?XmlNode $course,
        private readonly array $files,
        private readonly array $questions,
        private readonly array $bankEntries,
        private readonly array $gradeItems,
    ) {
    }

    public static function open(TarArchive $archive): self
    {
        $manifest = $archive->readOrNull(self::MANIFEST);

        if ($manifest === null) {
            throw new \RuntimeException('The archive holds no moodle_backup.xml, so it is not a Moodle course backup.');
        }

        $information = XmlNode::parse($manifest, self::MANIFEST)->child('information')
            ?? throw new \RuntimeException('moodle_backup.xml carries no <information> block.');

        $courseXml = $archive->readOrNull('course/course.xml');
        [$questions, $bankEntries] = self::indexQuestions($archive);

        return new self(
            $archive,
            $information,
            $courseXml === null ? null : XmlNode::parse($courseXml, 'course/course.xml'),
            self::indexFiles($archive),
            $questions,
            $bankEntries,
            self::indexGradeItems($archive),
        );
    }

    /**
     * The identity of the course inside the site it came from, used as the
     * import map's key. A Moodle course id alone would collide the day a
     * second site's backup arrives, so the site identifier hash (or the
     * wwwroot, on older archives) scopes it.
     */
    public function scope(string $fallback): string
    {
        $site = $this->information->text('original_site_identifier_hash')
            ?? $this->information->text('original_wwwroot');
        $courseId = $this->information->text('original_course_id');

        if ($courseId === null) {
            return $fallback;
        }

        return sprintf('moodle:%s/course/%s', $site ?? 'unknown-site', $courseId);
    }

    public function courseFormat(): ?string
    {
        return $this->information->text('original_course_format');
    }

    public function courseTitle(): ?string
    {
        return $this->course?->text('fullname') ?? $this->information->text('original_course_fullname');
    }

    public function courseShortName(): ?string
    {
        return $this->course?->text('shortname') ?? $this->information->text('original_course_shortname');
    }

    public function courseSummary(): ?string
    {
        return $this->course?->text('summary');
    }

    public function courseLanguage(): ?string
    {
        return $this->course?->text('lang');
    }

    /**
     * Sections in the order Moodle showed them, each carrying the course
     * module ids it held.
     *
     * @return list<MoodleSection>
     */
    public function sections(): array
    {
        $sections = [];

        foreach ($this->information->child('contents')?->child('sections')?->children('section') ?? [] as $entry) {
            $id = $entry->int('sectionid');
            $directory = $entry->text('directory');

            if ($id === null || $directory === null) {
                continue;
            }

            $sections[] = $this->section($id, $directory);
        }

        usort($sections, static fn (MoodleSection $a, MoodleSection $b): int => $a->number <=> $b->number);

        return $sections;
    }

    /**
     * The activities of one section, ordered by the section's own sequence
     * so a course reads in the order it was taught. Anything the sequence
     * does not name follows in manifest order.
     *
     * @return list<MoodleActivity>
     */
    public function activitiesOf(MoodleSection $section): array
    {
        $activities = [];

        foreach ($this->information->child('contents')?->child('activities')?->children('activity') ?? [] as $entry) {
            $id = $entry->int('moduleid');
            $modname = $entry->text('modulename');
            $directory = $entry->text('directory');

            if ($id === null || $modname === null || $directory === null) {
                continue;
            }

            if ($entry->int('sectionid') !== $section->id) {
                continue;
            }

            $activities[] = new MoodleActivity($id, $section->id, $modname, $entry->text('title'), $directory);
        }

        $order = array_flip($section->sequence);
        $fallback = \count($order);

        usort($activities, static function (MoodleActivity $a, MoodleActivity $b) use ($order, $fallback): int {
            return ($order[$a->id] ?? $fallback) <=> ($order[$b->id] ?? $fallback);
        });

        return $activities;
    }

    public function activityXml(MoodleActivity $activity): ?XmlNode
    {
        $raw = $this->archive->readOrNull($activity->xmlPath());

        return $raw === null ? null : XmlNode::parse($raw, $activity->xmlPath());
    }

    /**
     * The files one activity attached, from the file table rather than from
     * the archive listing: only files.xml knows the original name.
     *
     * @return list<array{hash: string, name: string, size: int}>
     */
    public function filesFor(?int $contextId, string $component, string $filearea): array
    {
        if ($contextId === null) {
            return [];
        }

        return $this->files[sprintf('%d|%s|%s', $contextId, $component, $filearea)] ?? [];
    }

    /**
     * The bytes of one stored file, or null when the archive left them out
     * (an "exclude files" backup does exactly that).
     */
    public function fileBytes(string $contentHash): ?string
    {
        if (preg_match('/^[a-f0-9]{40}$/i', $contentHash) !== 1) {
            return null;
        }

        $bytes = $this->archive->readOrNull(sprintf('files/%s/%s', substr($contentHash, 0, 2), $contentHash));
        return $bytes !== null && hash_equals(strtolower($contentHash), sha1($bytes)) ? $bytes : null;
    }

    /**
     * The question a quiz slot points at, whichever Moodle generation wrote
     * the archive, with the key the import map should remember it by.
     *
     * @return array{key: string, question: XmlNode}|null
     */
    public function questionOf(XmlNode $instance): ?array
    {
        $questionId = $instance->text('questionid');

        if ($questionId !== null && isset($this->questions[$questionId])) {
            return ['key' => 'question/' . $questionId, 'question' => $this->questions[$questionId]];
        }

        $entryId = $instance->child('question_reference')?->text('questionbankentryid');

        if ($entryId !== null && isset($this->bankEntries[$entryId])) {
            return ['key' => 'bank-entry/' . $entryId, 'question' => $this->bankEntries[$entryId]];
        }

        return null;
    }

    /**
     * The pass mark of an activity as a percentage, read from the gradebook
     * item Moodle keeps outside the activity. Zero when the course set none.
     */
    public function passMarkOf(string $module, ?int $instanceId): int
    {
        if ($instanceId === null) {
            return 0;
        }

        $item = $this->gradeItems[$module . '/' . $instanceId] ?? null;

        if ($item === null || $item['grademax'] <= 0.0 || $item['gradepass'] <= 0.0) {
            return 0;
        }

        return max(0, min(100, (int) round($item['gradepass'] / $item['grademax'] * 100)));
    }

    private function section(int $id, string $directory): MoodleSection
    {
        $raw = $this->archive->readOrNull(rtrim($directory, '/') . '/section.xml');

        if ($raw === null) {
            return new MoodleSection($id, $id, null, null, []);
        }

        $node = XmlNode::parse($raw, $directory . '/section.xml');
        $sequence = [];

        foreach (explode(',', $node->text('sequence') ?? '') as $cmid) {
            $cmid = trim($cmid);

            if (preg_match('/^\d+$/', $cmid) === 1) {
                $sequence[] = (int) $cmid;
            }
        }

        return new MoodleSection($id, $node->int('number') ?? $id, $node->text('name'), $node->text('summary'), $sequence);
    }

    /**
     * @return array<string, list<array{hash: string, name: string, size: int}>>
     */
    private static function indexFiles(TarArchive $archive): array
    {
        $raw = $archive->readOrNull('files.xml');

        if ($raw === null) {
            return [];
        }

        $files = [];

        foreach (XmlNode::parse($raw, 'files.xml')->children('file') as $file) {
            $name = $file->text('filename');
            $hash = $file->text('contenthash');
            $context = $file->int('contextid');
            $component = $file->text('component');
            $area = $file->text('filearea');

            // Moodle records a directory as a file named ".", which is not one.
            if ($name === null || $name === '.' || $hash === null || $context === null || $component === null || $area === null) {
                continue;
            }

            $path = trim($file->text('filepath') ?? '/', '/');
            $files[sprintf('%d|%s|%s', $context, $component, $area)][] = [
                'hash' => $hash,
                'name' => $path === '' ? $name : $path . '/' . $name,
                'size' => $file->int('filesize') ?? 0,
            ];
        }

        return $files;
    }

    /**
     * Reads every question bank in the archive: the root one, plus the
     * per-activity banks newer Moodle writes beside the activity.
     *
     * @return array{array<string, XmlNode>, array<string, XmlNode>}
     */
    private static function indexQuestions(TarArchive $archive): array
    {
        $questions = [];
        $bankEntries = [];
        $versions = [];

        foreach ($archive->paths() as $path) {
            if ($path !== 'questions.xml' && !str_ends_with($path, '/questions.xml')) {
                continue;
            }

            $raw = $archive->readOrNull($path);

            if ($raw === null) {
                continue;
            }

            foreach (XmlNode::parse($raw, $path)->children('question_category') as $category) {
                self::readLegacyBank($category, $questions);
                self::readVersionedBank($category, $questions, $bankEntries, $versions);
            }
        }

        return [$questions, $bankEntries];
    }

    /**
     * @param array<string, XmlNode> $questions
     */
    private static function readLegacyBank(XmlNode $category, array &$questions): void
    {
        foreach ($category->children('questions') as $bank) {
            foreach ($bank->children('question') as $question) {
                $id = $question->attribute('id');

                if ($id !== null) {
                    $questions[$id] = $question;
                }
            }
        }
    }

    /**
     * @param array<string, XmlNode> $questions
     * @param array<string, XmlNode> $bankEntries
     * @param array<string, int>     $versions    the version number already kept per bank entry
     */
    private static function readVersionedBank(
        XmlNode $category,
        array &$questions,
        array &$bankEntries,
        array &$versions,
    ): void {
        foreach ($category->child('question_bank_entries')?->children('question_bank_entry') ?? [] as $entry) {
            $entryId = $entry->attribute('id');

            foreach ($entry->children('question_version') as $wrapper) {
                foreach ($wrapper->children('question_versions') as $version) {
                    $number = $version->int('version') ?? 0;

                    foreach ($version->children('questions') as $bank) {
                        foreach ($bank->children('question') as $question) {
                            $id = $question->attribute('id');

                            if ($id !== null) {
                                $questions[$id] = $question;
                            }

                            if ($entryId === null || $number < ($versions[$entryId] ?? 0)) {
                                continue;
                            }

                            $versions[$entryId] = $number;
                            $bankEntries[$entryId] = $question;
                        }
                    }
                }
            }
        }
    }

    /**
     * @return array<string, array{grademax: float, gradepass: float}>
     */
    private static function indexGradeItems(TarArchive $archive): array
    {
        $raw = $archive->readOrNull('gradebook.xml');

        if ($raw === null) {
            return [];
        }

        $items = [];
        $gradebook = XmlNode::parse($raw, 'gradebook.xml');

        foreach ($gradebook->child('grade_items')?->children('grade_item') ?? [] as $item) {
            $module = $item->text('itemmodule');
            $instance = $item->int('iteminstance');

            if ($module === null || $instance === null) {
                continue;
            }

            $items[$module . '/' . $instance] = [
                'grademax' => $item->float('grademax') ?? 0.0,
                'gradepass' => $item->float('gradepass') ?? 0.0,
            ];
        }

        return $items;
    }
}
