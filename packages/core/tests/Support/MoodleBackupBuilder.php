<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

/**
 * Builds a .mbz the way Moodle writes one: moodle_backup.xml listing the
 * sections and activities, course/course.xml, one directory per section and
 * per activity, files.xml plus the content addressed bytes, questions.xml and
 * gradebook.xml.
 *
 * The fixtures are generated rather than committed so the layout under test
 * is visible and editable in the test itself. Both question bank shapes are
 * available: the Moodle 4.x bank entry with versions (the default) and the
 * 3.x category nesting, through legacyQuestionBank().
 */
final class MoodleBackupBuilder
{
    public const SITE_HASH = 'b3f1c0d2e4a5968778695a4b3c2d1e0f9a8b7c6d';
    public const COURSE_CONTEXT = 45;

    private string $fullName = 'Foundations of Tea';
    private string $shortName = 'TEA 101';
    private ?string $summary = '<p>Leaf, water, time.</p>';
    private string $format = 'topics';
    private int $courseId = 2;
    private bool $legacyBank = false;
    private bool $withCourseXml = true;

    /** @var list<array{id: int, number: int, name: ?string, summary: ?string}> */
    private array $sections = [];

    /** @var list<array{cmid: int, section: int, mod: string, title: string, context: int, body: string}> */
    private array $activities = [];

    /**
     * @var list<array{context: int, component: string, area: string, hash: string,
     *             name: string, size: ?int, bytes: ?string, filepath: string}>
     */
    private array $files = [];

    /** @var list<array{id: int, entry: int, version: int, xml: string}> */
    private array $questions = [];

    /** @var list<array{module: string, instance: int, grademax: float, gradepass: float}> */
    private array $gradeItems = [];

    public function course(string $fullName, string $shortName, ?string $summary = null): self
    {
        $this->fullName = $fullName;
        $this->shortName = $shortName;
        $this->summary = $summary;

        return $this;
    }

    public function format(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function legacyQuestionBank(): self
    {
        $this->legacyBank = true;

        return $this;
    }

    public function withoutCourseXml(): self
    {
        $this->withCourseXml = false;

        return $this;
    }

    public function section(int $id, int $number, ?string $name = null, ?string $summary = null): self
    {
        $this->sections[] = ['id' => $id, 'number' => $number, 'name' => $name, 'summary' => $summary];

        return $this;
    }

    public function page(int $cmid, int $section, string $title, string $content): self
    {
        return $this->activity($cmid, $section, 'page', $title, sprintf(
            '<name>%s</name><intro>%s</intro><introformat>1</introformat>'
            . '<content>%s</content><contentformat>1</contentformat>'
            . '<legacyfiles>0</legacyfiles><legacyfileslast>$@NULL@$</legacyfileslast>'
            . '<display>5</display><displayoptions>a:1:{s:12:"printheading";i:1;}</displayoptions>'
            . '<revision>1</revision><timemodified>1767225600</timemodified>',
            self::escape($title),
            self::escape('<p>Start here.</p>'),
            self::escape($content),
        ));
    }

    public function label(int $cmid, int $section, string $title, string $intro): self
    {
        return $this->activity($cmid, $section, 'label', $title, sprintf(
            '<name>%s</name><intro>%s</intro><introformat>1</introformat><timemodified>1767225600</timemodified>',
            self::escape($title),
            self::escape($intro),
        ));
    }

    /**
     * @param list<array{title: string, content: string, pagenum: int}> $chapters
     */
    public function book(int $cmid, int $section, string $title, array $chapters): self
    {
        $inner = '';

        foreach ($chapters as $index => $chapter) {
            $inner .= sprintf(
                '<chapter id="%d"><pagenum>%d</pagenum><subchapter>0</subchapter><title>%s</title>'
                . '<content>%s</content><contentformat>1</contentformat><hidden>0</hidden>'
                . '<timecreated>1767225600</timecreated><timemodified>1767225600</timemodified>'
                . '<importsrc>0</importsrc></chapter>',
                900 + $index,
                $chapter['pagenum'],
                self::escape($chapter['title']),
                self::escape($chapter['content']),
            );
        }

        return $this->activity($cmid, $section, 'book', $title, sprintf(
            '<name>%s</name><intro>%s</intro><introformat>1</introformat>'
            . '<numbering>1</numbering><navstyle>1</navstyle><customtitles>0</customtitles>'
            . '<revision>1</revision><timecreated>1767225600</timecreated><timemodified>1767225600</timemodified>'
            . '<chapters>%s</chapters>',
            self::escape($title),
            self::escape('<p>Read it through.</p>'),
            $inner,
        ));
    }

    public function url(int $cmid, int $section, string $title, string $url): self
    {
        return $this->activity($cmid, $section, 'url', $title, sprintf(
            '<name>%s</name><intro>%s</intro><introformat>1</introformat>'
            . '<externalurl>%s</externalurl><display>0</display>'
            . '<displayoptions>a:1:{s:10:"printintro";i:1;}</displayoptions>'
            . '<parameters>a:0:{}</parameters><timemodified>1767225600</timemodified>',
            self::escape($title),
            self::escape('<p>Watch this.</p>'),
            self::escape($url),
        ));
    }

    public function resource(int $cmid, int $section, string $title): self
    {
        return $this->activity($cmid, $section, 'resource', $title, sprintf(
            '<name>%s</name><intro>%s</intro><introformat>1</introformat>'
            . '<tobemigrated>0</tobemigrated><legacyfiles>0</legacyfiles>'
            . '<legacyfileslast>$@NULL@$</legacyfileslast><display>0</display>'
            . '<displayoptions>a:0:{}</displayoptions><filterfiles>0</filterfiles>'
            . '<revision>1</revision><timemodified>1767225600</timemodified>',
            self::escape($title),
            self::escape('<p>The handbook.</p>'),
        ));
    }

    public function folder(int $cmid, int $section, string $title): self
    {
        return $this->activity($cmid, $section, 'folder', $title, sprintf(
            '<name>%s</name><intro>%s</intro><introformat>1</introformat>'
            . '<revision>1</revision><timemodified>1767225600</timemodified>'
            . '<display>0</display><showexpanded>1</showexpanded><showdownloadfolder>1</showdownloadfolder>',
            self::escape($title),
            self::escape('<p>Everything else.</p>'),
        ));
    }

    public function assign(int $cmid, int $section, string $title, string $intro): self
    {
        return $this->activity($cmid, $section, 'assign', $title, sprintf(
            '<name>%s</name><intro>%s</intro><introformat>1</introformat>'
            . '<alwaysshowdescription>1</alwaysshowdescription><submissiondrafts>0</submissiondrafts>'
            . '<sendnotifications>0</sendnotifications><duedate>0</duedate>'
            . '<allowsubmissionsfromdate>0</allowsubmissionsfromdate><grade>100</grade>'
            . '<timemodified>1767225600</timemodified><completionsubmit>0</completionsubmit>'
            . '<cutoffdate>0</cutoffdate><teamsubmission>0</teamsubmission>'
            . '<userflags></userflags><submissions></submissions><grades></grades>',
            self::escape($title),
            self::escape($intro),
        ));
    }

    public function forum(int $cmid, int $section, string $title): self
    {
        return $this->activity($cmid, $section, 'forum', $title, sprintf(
            '<name>%s</name><type>general</type><intro>%s</intro><introformat>1</introformat>',
            self::escape($title),
            self::escape('<p>Say hello.</p>'),
        ));
    }

    /**
     * @param list<array{question: int, entry: int, maxmark: float}> $slots
     */
    public function quiz(
        int $cmid,
        int $section,
        string $title,
        array $slots,
        int $timeLimit = 0,
        int $attempts = 0,
    ): self {
        $instances = '';

        foreach ($slots as $index => $slot) {
            $reference = $this->legacyBank
                ? sprintf('<questionid>%d</questionid>', $slot['question'])
                : sprintf(
                    '<question_reference id="%d"><usingcontextid>%d</usingcontextid><component>mod_quiz</component>'
                    . '<questionarea>slot</questionarea><questionbankentryid>%d</questionbankentryid>'
                    . '<version>$@NULL@$</version></question_reference>',
                    700 + $index,
                    self::COURSE_CONTEXT,
                    $slot['entry'],
                );

            $instances .= sprintf(
                '<question_instance id="%d"><slot>%d</slot><page>%d</page>'
                . '<requireprevious>0</requireprevious><maxmark>%.7F</maxmark>%s</question_instance>',
                600 + $index,
                $index + 1,
                $index + 1,
                $slot['maxmark'],
                $reference,
            );
        }

        return $this->activity($cmid, $section, 'quiz', $title, sprintf(
            '<name>%s</name><intro>%s</intro><introformat>1</introformat>'
            . '<timeopen>0</timeopen><timeclose>0</timeclose><timelimit>%d</timelimit>'
            . '<overduehandling>autosubmit</overduehandling><graceperiod>0</graceperiod>'
            . '<preferredbehaviour>deferredfeedback</preferredbehaviour>'
            . '<attempts_number>%d</attempts_number><attemptonlast>0</attemptonlast>'
            . '<grademethod>1</grademethod><decimalpoints>2</decimalpoints>'
            . '<questiondecimalpoints>-1</questiondecimalpoints><questionsperpage>1</questionsperpage>'
            . '<navmethod>free</navmethod><shuffleanswers>1</shuffleanswers>'
            . '<sumgrades>10.00000</sumgrades><grade>10.00000</grade>'
            . '<timecreated>1767225600</timecreated><timemodified>1767225600</timemodified>'
            . '<password></password><subnet></subnet><browsersecurity>-</browsersecurity>'
            . '<question_instances>%s</question_instances><feedbacks></feedbacks>'
            . '<overrides></overrides><grades></grades><attempts></attempts>',
            self::escape($title),
            self::escape('<p>Six ways to be right.</p>'),
            $timeLimit,
            $attempts,
            $instances,
        ));
    }

    public function gradeItem(string $module, int $instance, float $grademax, float $gradepass): self
    {
        $this->gradeItems[] = [
            'module' => $module,
            'instance' => $instance,
            'grademax' => $grademax,
            'gradepass' => $gradepass,
        ];

        return $this;
    }

    /**
     * @param string|null $bytes    null leaves the file out of the archive,
     *                              which is what an "exclude files" backup does
     * @param int|null    $size     null reports the real length
     * @param string      $filepath the directory inside a Moodle file area
     */
    public function file(
        int $context,
        string $component,
        string $area,
        string $hash,
        string $name,
        ?string $bytes,
        ?int $size = null,
        string $filepath = '/',
    ): self {
        $this->files[] = [
            'context' => $context,
            'component' => $component,
            'area' => $area,
            'hash' => $bytes === null ? $hash : sha1($bytes),
            'name' => $name,
            'size' => $size,
            'bytes' => $bytes,
            'filepath' => $filepath,
        ];

        return $this;
    }

    public function question(int $id, int $entry, string $qtype, string $name, string $text, float $mark, string $plugin): self
    {
        $this->questions[] = [
            'id' => $id,
            'entry' => $entry,
            'version' => 1,
            'xml' => sprintf(
                '<question id="%d"><parent>0</parent><name>%s</name><questiontext>%s</questiontext>'
                . '<questiontextformat>1</questiontextformat><generalfeedback></generalfeedback>'
                . '<generalfeedbackformat>1</generalfeedbackformat><defaultmark>%.7F</defaultmark>'
                . '<penalty>0.3333333</penalty><qtype>%s</qtype><length>1</length>'
                . '<stamp>moodle.example.test+%d</stamp><timecreated>1767225600</timecreated>'
                . '<timemodified>1767225600</timemodified><createdby>2</createdby><modifiedby>2</modifiedby>'
                . '%s</question>',
                $id,
                self::escape($name),
                self::escape($text),
                $mark,
                $qtype,
                $id,
                $plugin,
            ),
        ];

        return $this;
    }

    /**
     * @param list<array{id: int, text: string, fraction: float}> $answers
     */
    public function multipleChoice(int $id, int $entry, string $name, string $text, array $answers, bool $single, float $mark = 1.0): self
    {
        return $this->question($id, $entry, 'multichoice', $name, $text, $mark, sprintf(
            '<plugin_qtype_multichoice_question>%s<multichoice id="%d"><layout>0</layout>'
            . '<single>%d</single><shuffleanswers>1</shuffleanswers>'
            . '<correctfeedback></correctfeedback><correctfeedbackformat>1</correctfeedbackformat>'
            . '<partiallycorrectfeedback></partiallycorrectfeedback>'
            . '<partiallycorrectfeedbackformat>1</partiallycorrectfeedbackformat>'
            . '<incorrectfeedback></incorrectfeedback><incorrectfeedbackformat>1</incorrectfeedbackformat>'
            . '<answernumbering>abc</answernumbering><shownumcorrect>1</shownumcorrect>'
            . '</multichoice></plugin_qtype_multichoice_question>',
            self::answers($answers),
            $id,
            $single ? 1 : 0,
        ));
    }

    public function trueFalse(int $id, int $entry, string $name, string $text, bool $correct, float $mark = 1.0): self
    {
        $answers = [
            ['id' => $id * 10 + 1, 'text' => 'True', 'fraction' => $correct ? 1.0 : 0.0],
            ['id' => $id * 10 + 2, 'text' => 'False', 'fraction' => $correct ? 0.0 : 1.0],
        ];

        return $this->question($id, $entry, 'truefalse', $name, $text, $mark, sprintf(
            '<plugin_qtype_truefalse_question>%s<truefalse id="%d"><trueanswer>%d</trueanswer>'
            . '<falseanswer>%d</falseanswer><showstandardinstruction>1</showstandardinstruction>'
            . '</truefalse></plugin_qtype_truefalse_question>',
            self::answers($answers),
            $id,
            $id * 10 + 1,
            $id * 10 + 2,
        ));
    }

    /**
     * @param list<string> $accepted
     */
    public function shortAnswer(
        int $id,
        int $entry,
        string $name,
        string $text,
        array $accepted,
        bool $caseSensitive = false,
        float $mark = 1.0,
    ): self {
        $answers = [];

        foreach ($accepted as $index => $value) {
            $answers[] = ['id' => $id * 10 + $index + 1, 'text' => $value, 'fraction' => 1.0];
        }

        return $this->question($id, $entry, 'shortanswer', $name, $text, $mark, sprintf(
            '<plugin_qtype_shortanswer_question>%s<shortanswer id="%d"><usecase>%d</usecase>'
            . '</shortanswer></plugin_qtype_shortanswer_question>',
            self::answers($answers),
            $id,
            $caseSensitive ? 1 : 0,
        ));
    }

    /**
     * @param list<string> $accepted
     */
    public function numerical(int $id, int $entry, string $name, string $text, array $accepted, float $tolerance = 0.0): self
    {
        $answers = [];
        $records = '';

        foreach ($accepted as $index => $value) {
            $answerId = $id * 10 + $index + 1;
            $answers[] = ['id' => $answerId, 'text' => $value, 'fraction' => 1.0];
            $records .= sprintf(
                '<numerical_record id="%d"><answer>%d</answer><tolerance>%.7F</tolerance></numerical_record>',
                $answerId,
                $answerId,
                $tolerance,
            );
        }

        return $this->question($id, $entry, 'numerical', $name, $text, 1.0, sprintf(
            '<plugin_qtype_numerical_question>%s<numerical_records>%s</numerical_records>'
            . '<numerical_options id="%d"><showunits>3</showunits><unitsleft>0</unitsleft>'
            . '<unitgradingtype>0</unitgradingtype><unitpenalty>0.1000000</unitpenalty></numerical_options>'
            . '<numerical_units></numerical_units></plugin_qtype_numerical_question>',
            self::answers($answers),
            $records,
            $id,
        ));
    }

    public function essay(int $id, int $entry, string $name, string $text, float $mark = 1.0): self
    {
        return $this->question($id, $entry, 'essay', $name, $text, $mark, sprintf(
            '<plugin_qtype_essay_question><essay id="%d"><responseformat>editor</responseformat>'
            . '<responserequired>1</responserequired><responsefieldlines>10</responsefieldlines>'
            . '<attachments>0</attachments><graderinfo></graderinfo><graderinfoformat>1</graderinfoformat>'
            . '<responsetemplate></responsetemplate><responsetemplateformat>1</responsetemplateformat>'
            . '</essay></plugin_qtype_essay_question>',
            $id,
        ));
    }

    /**
     * A Moodle matching question, which has no VidyaLMS equivalent.
     */
    public function matching(int $id, int $entry, string $name, string $text, float $mark = 1.0): self
    {
        return $this->question($id, $entry, 'match', $name, $text, $mark, sprintf(
            '<plugin_qtype_match_question><matchoptions id="%d"><shuffleanswers>1</shuffleanswers>'
            . '<correctfeedback></correctfeedback><correctfeedbackformat>1</correctfeedbackformat>'
            . '<partiallycorrectfeedback></partiallycorrectfeedback>'
            . '<partiallycorrectfeedbackformat>1</partiallycorrectfeedbackformat>'
            . '<incorrectfeedback></incorrectfeedback><incorrectfeedbackformat>1</incorrectfeedbackformat>'
            . '<shownumcorrect>1</shownumcorrect></matchoptions>'
            . '<matches><match id="%d"><questiontext>Green</questiontext><questiontextformat>1</questiontextformat>'
            . '<answertext>China</answertext></match></matches></plugin_qtype_match_question>',
            $id,
            $id + 1,
        ));
    }

    /**
     * The finished .mbz bytes.
     */
    public function build(): string
    {
        return TarWriter::gzip($this->entries());
    }

    /**
     * @return array<string, string>
     */
    public function entries(): array
    {
        $entries = ['moodle_backup.xml' => $this->manifest()];

        if ($this->withCourseXml) {
            $entries['course/course.xml'] = $this->courseXml();
            $entries['course/inforef.xml'] = self::document('inforef', '');
        }

        foreach ($this->sections as $section) {
            $entries[sprintf('sections/section_%d/section.xml', $section['id'])] = $this->sectionXml($section);
            $entries[sprintf('sections/section_%d/inforef.xml', $section['id'])] = self::document('inforef', '');
        }

        foreach ($this->activities as $activity) {
            $directory = sprintf('activities/%s_%d', $activity['mod'], $activity['cmid']);
            $entries[$directory . '/' . $activity['mod'] . '.xml'] = $activity['body'];
            $entries[$directory . '/module.xml'] = $this->moduleXml($activity);
            $entries[$directory . '/inforef.xml'] = self::document('inforef', '');
        }

        $entries['files.xml'] = $this->filesXml();
        $entries['questions.xml'] = $this->questionsXml();
        $entries['gradebook.xml'] = $this->gradebookXml();

        foreach ($this->files as $file) {
            if ($file['bytes'] !== null) {
                $entries[sprintf('files/%s/%s', substr($file['hash'], 0, 2), $file['hash'])] = $file['bytes'];
            }
        }

        return $entries;
    }

    private function activity(int $cmid, int $section, string $mod, string $title, string $inner): self
    {
        $context = 1000 + $cmid;
        $this->activities[] = [
            'cmid' => $cmid,
            'section' => $section,
            'mod' => $mod,
            'title' => $title,
            'context' => $context,
            'body' => self::document('activity', sprintf('<%s id="%d">%s</%s>', $mod, self::instanceOf($cmid), $inner, $mod), sprintf(
                ' id="%d" moduleid="%d" modulename="%s" contextid="%d"',
                self::instanceOf($cmid),
                $cmid,
                $mod,
                $context,
            )),
        ];

        return $this;
    }

    /**
     * Moodle's instance id and course module id are different numbers, and
     * the gradebook keys on the instance. Keeping them apart in the fixture
     * proves the importer reads the right one.
     */
    public static function instanceOf(int $cmid): int
    {
        return $cmid - 400;
    }

    public static function contextOf(int $cmid): int
    {
        return 1000 + $cmid;
    }

    private function manifest(): string
    {
        $activities = '';

        foreach ($this->activities as $activity) {
            $activities .= sprintf(
                '<activity><moduleid>%d</moduleid><sectionid>%d</sectionid><modulename>%s</modulename>'
                . '<title>%s</title><directory>activities/%s_%d</directory></activity>',
                $activity['cmid'],
                $activity['section'],
                $activity['mod'],
                self::escape($activity['title']),
                $activity['mod'],
                $activity['cmid'],
            );
        }

        $sections = '';

        foreach ($this->sections as $section) {
            $sections .= sprintf(
                '<section><sectionid>%d</sectionid><title>%d</title><directory>sections/section_%d</directory></section>',
                $section['id'],
                $section['number'],
                $section['id'],
            );
        }

        $information = sprintf(
            '<name>backup-moodle2-course-%d-%s-20260101-1200.mbz</name>'
            . '<moodle_version>2023100900</moodle_version><moodle_release>4.3.1 (Build: 20231211)</moodle_release>'
            . '<backup_version>2023100900</backup_version><backup_release>4.3</backup_release>'
            . '<backup_date>1767225600</backup_date><mnet_remoteusers>0</mnet_remoteusers>'
            . '<include_file_references_to_external_content>0</include_file_references_to_external_content>'
            . '<original_wwwroot>https://moodle.example.test</original_wwwroot>'
            . '<original_site_identifier_hash>%s</original_site_identifier_hash>'
            . '<original_course_id>%d</original_course_id><original_course_format>%s</original_course_format>'
            . '<original_course_fullname>%s</original_course_fullname>'
            . '<original_course_shortname>%s</original_course_shortname>'
            . '<original_course_startdate>1767225600</original_course_startdate>'
            . '<original_course_contextid>%d</original_course_contextid>'
            . '<original_system_contextid>1</original_system_contextid>'
            . '<details><detail backup_id="a1b2c3"><type>course</type><format>moodle2</format>'
            . '<interactive>1</interactive><mode>10</mode><execution>1</execution>'
            . '<executiontime>0</executiontime></detail></details>'
            . '<contents><activities>%s</activities><sections>%s</sections>'
            . '<course><courseid>%d</courseid><title>%s</title><directory>course</directory></course></contents>'
            . '<settings><setting><level>root</level><name>users</name><value>0</value></setting></settings>',
            $this->courseId,
            strtolower(str_replace(' ', '', $this->shortName)),
            self::SITE_HASH,
            $this->courseId,
            $this->format,
            self::escape($this->fullName),
            self::escape($this->shortName),
            self::COURSE_CONTEXT,
            $activities,
            $sections,
            $this->courseId,
            self::escape($this->shortName),
        );

        return self::document('moodle_backup', '<information>' . $information . '</information>');
    }

    private function courseXml(): string
    {
        $inner = sprintf(
            '<shortname>%s</shortname><fullname>%s</fullname><idnumber></idnumber>'
            . '<summary>%s</summary><summaryformat>1</summaryformat><format>%s</format>'
            . '<showgrades>1</showgrades><newsitems>5</newsitems><startdate>1767225600</startdate>'
            . '<enddate>0</enddate><lang>en</lang><visible>1</visible>'
            . '<category id="1"><name>Miscellaneous</name><description>$@NULL@$</description></category>'
            . '<tags></tags>',
            self::escape($this->shortName),
            self::escape($this->fullName),
            $this->summary === null ? '$@NULL@$' : self::escape($this->summary),
            $this->format,
        );

        return self::document('course', $inner, sprintf(' id="%d" contextid="%d"', $this->courseId, self::COURSE_CONTEXT));
    }

    /**
     * @param array{id: int, number: int, name: ?string, summary: ?string} $section
     */
    private function sectionXml(array $section): string
    {
        $sequence = [];

        foreach ($this->activities as $activity) {
            if ($activity['section'] === $section['id']) {
                $sequence[] = $activity['cmid'];
            }
        }

        $inner = sprintf(
            '<number>%d</number><name>%s</name><summary>%s</summary><summaryformat>1</summaryformat>'
            . '<sequence>%s</sequence><visible>1</visible><availabilityjson>$@NULL@$</availabilityjson>'
            . '<timemodified>1767225600</timemodified>',
            $section['number'],
            $section['name'] === null ? '$@NULL@$' : self::escape($section['name']),
            $section['summary'] === null ? '$@NULL@$' : self::escape($section['summary']),
            implode(',', $sequence),
        );

        return self::document('section', $inner, sprintf(' id="%d"', $section['id']));
    }

    /**
     * @param array{cmid: int, section: int, mod: string, title: string, context: int, body: string} $activity
     */
    private function moduleXml(array $activity): string
    {
        $inner = sprintf(
            '<modulename>%s</modulename><sectionid>%d</sectionid><sectionnumber>0</sectionnumber>'
            . '<idnumber></idnumber><added>1767225600</added><score>0</score><indent>0</indent>'
            . '<visible>1</visible><visibleoncoursepage>1</visibleoncoursepage><visibleold>1</visibleold>'
            . '<groupmode>0</groupmode><groupingid>0</groupingid><completion>1</completion>'
            . '<completiongradeitemnumber>$@NULL@$</completiongradeitemnumber><completionview>0</completionview>'
            . '<completionexpected>0</completionexpected><availability>$@NULL@$</availability>'
            . '<showdescription>0</showdescription><tags></tags>',
            $activity['mod'],
            $activity['section'],
        );

        return self::document('module', $inner, sprintf(' id="%d" version="2023100900"', $activity['cmid']));
    }

    private function filesXml(): string
    {
        $inner = '';

        foreach ($this->files as $index => $file) {
            $inner .= sprintf(
                '<file id="%d"><contenthash>%s</contenthash><contextid>%d</contextid>'
                . '<component>%s</component><filearea>%s</filearea><itemid>0</itemid>'
                . '<filepath>%s</filepath><filename>%s</filename><userid>2</userid>'
                . '<filesize>%d</filesize><mimetype>application/octet-stream</mimetype>'
                . '<status>0</status><timecreated>1767225600</timecreated><timemodified>1767225600</timemodified>'
                . '<source>%s</source><author>Ada</author><license>allrightsreserved</license>'
                . '<sortorder>0</sortorder><repositorytype>$@NULL@$</repositorytype>'
                . '<repositoryid>$@NULL@$</repositoryid><reference>$@NULL@$</reference></file>',
                500 + $index,
                $file['hash'],
                $file['context'],
                $file['component'],
                $file['area'],
                self::escape($file['filepath']),
                self::escape($file['name']),
                $file['size'] ?? \strlen((string) $file['bytes']),
                self::escape($file['name']),
            );
        }

        return self::document('files', $inner);
    }

    private function questionsXml(): string
    {
        if ($this->questions === []) {
            return self::document('question_categories', '');
        }

        $inner = '';

        if ($this->legacyBank) {
            foreach ($this->questions as $question) {
                $inner .= $question['xml'];
            }

            $inner = '<questions>' . $inner . '</questions>';
        } else {
            foreach ($this->questions as $question) {
                $inner .= sprintf(
                    '<question_bank_entry id="%d"><questioncategoryid>10</questioncategoryid>'
                    . '<idnumber>$@NULL@$</idnumber><ownerid>2</ownerid>'
                    . '<question_version><question_versions id="%d"><version>%d</version>'
                    . '<status>ready</status><questions>%s</questions></question_versions></question_version>'
                    . '</question_bank_entry>',
                    $question['entry'],
                    $question['entry'] + 100,
                    $question['version'],
                    $question['xml'],
                );
            }

            $inner = '<question_bank_entries>' . $inner . '</question_bank_entries>';
        }

        $category = sprintf(
            '<question_category id="10"><name>Default for %s</name><contextid>%d</contextid>'
            . '<contextlevel>50</contextlevel><contextinstanceid>%d</contextinstanceid>'
            . '<info>The default category.</info><infoformat>1</infoformat>'
            . '<stamp>moodle.example.test+10</stamp><parent>0</parent><sortorder>999</sortorder>'
            . '<idnumber>$@NULL@$</idnumber>%s</question_category>',
            self::escape($this->shortName),
            self::COURSE_CONTEXT,
            $this->courseId,
            $inner,
        );

        return self::document('question_categories', $category);
    }

    private function gradebookXml(): string
    {
        $items = '';

        foreach ($this->gradeItems as $index => $item) {
            $items .= sprintf(
                '<grade_item id="%d"><categoryid>1</categoryid><itemname>Graded</itemname>'
                . '<itemtype>mod</itemtype><itemmodule>%s</itemmodule><iteminstance>%d</iteminstance>'
                . '<itemnumber>0</itemnumber><iteminfo>$@NULL@$</iteminfo><idnumber>$@NULL@$</idnumber>'
                . '<calculation>$@NULL@$</calculation><gradetype>1</gradetype><grademax>%.5F</grademax>'
                . '<grademin>0.00000</grademin><scaleid>$@NULL@$</scaleid><outcomeid>$@NULL@$</outcomeid>'
                . '<gradepass>%.5F</gradepass><multfactor>1.00000</multfactor><plusfactor>0.00000</plusfactor>'
                . '<aggregationcoef>0.00000</aggregationcoef><sortorder>%d</sortorder>'
                . '<hidden>0</hidden><locked>0</locked><timecreated>1767225600</timecreated>'
                . '<timemodified>1767225600</timemodified><grade_grades></grade_grades></grade_item>',
                800 + $index,
                $item['module'],
                $item['instance'],
                $item['grademax'],
                $item['gradepass'],
                $index + 1,
            );
        }

        return self::document(
            'gradebook',
            '<grade_categories></grade_categories><grade_items>' . $items . '</grade_items>'
            . '<grade_letters></grade_letters><grade_settings></grade_settings>',
        );
    }

    /**
     * @param list<array{id: int, text: string, fraction: float}> $answers
     */
    private static function answers(array $answers): string
    {
        $inner = '';

        foreach ($answers as $answer) {
            $inner .= sprintf(
                '<answer id="%d"><answertext>%s</answertext><answerformat>1</answerformat>'
                . '<fraction>%.7F</fraction><feedback></feedback><feedbackformat>1</feedbackformat></answer>',
                $answer['id'],
                self::escape($answer['text']),
                $answer['fraction'],
            );
        }

        return '<answers>' . $inner . '</answers>';
    }

    private static function document(string $root, string $inner, string $attributes = ''): string
    {
        return sprintf('<?xml version="1.0" encoding="UTF-8"?>%s<%s%s>%s</%s>', "\n", $root, $attributes, $inner, $root);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
