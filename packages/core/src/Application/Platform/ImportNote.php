<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

/**
 * One line of an import's story. Notes are what the admin screen shows while
 * a long migration runs, so they name the thing they are about ("course
 * 'Intro to Tea' created", "question type 'drag_drop' has no equivalent,
 * skipped") rather than describing the code.
 *
 * The level is structured data and the message is not allowed to look like
 * one. Every message an importer composes quotes text it did not write: a
 * course title, a lesson type, an exception the source provoked. A title
 * holding a line break and "[error]" would otherwise write a second line into
 * the job log that VidyaLMS never emitted, and a reader counting errors would
 * count it. The constructor is where that is stopped, because it is the one
 * place every note goes through: control characters and line breaks become
 * spaces, so one note is one line and the only "[level]" prefix on it is the
 * one line() writes.
 *
 * Length is bounded here for the same reason. The job log is rewritten whole
 * on every progress flush and rendered whole by both admin screens, so a
 * single note quoting a megabyte of source text would be paid for on every
 * write and every render. excerpt() is what an importer quotes a source value
 * through; MESSAGE_LIMIT is the backstop for everything else.
 */
final class ImportNote
{
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /** The longest one note may be. Composed text is far shorter; source text is not. */
    public const MESSAGE_LIMIT = 1000;

    /** How much of one source-controlled value a note quotes. */
    public const EXCERPT_LIMIT = 120;

    private const LEVELS = [self::INFO, self::WARNING, self::ERROR];

    private const ELLIPSIS = '...';

    public readonly string $level;

    public readonly string $message;

    public function __construct(string $level, string $message)
    {
        $this->level = \in_array($level, self::LEVELS, true) ? $level : self::INFO;
        $this->message = self::oneLine($message, self::MESSAGE_LIMIT);
    }

    public static function of(string $level, string $message): self
    {
        return new self($level, $message);
    }

    public static function info(string $message): self
    {
        return new self(self::INFO, $message);
    }

    public static function warning(string $message): self
    {
        return new self(self::WARNING, $message);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, $message);
    }

    /**
     * A source-controlled value as a note may quote it: one line, short
     * enough that the sentence around it survives, and cut without leaving
     * half a UTF-8 character behind.
     */
    public static function excerpt(string $value, int $limit = self::EXCERPT_LIMIT): string
    {
        return self::oneLine($value, max(1, $limit));
    }

    /**
     * Reads back one job log line, and is the exact inverse of line() for
     * every message, including one that begins with a level marker of its
     * own: line() writes the "[info] " prefix in exactly the case where a
     * plain line would be read back as a level nothing reported.
     */
    public static function fromLine(string $line): self
    {
        $level = self::levelOf($line);

        return $level === null
            ? new self(self::INFO, $line)
            : new self($level, substr($line, \strlen(self::prefix($level))));
    }

    /**
     * The notes of a stored job log, levels and all. Both admin screens read
     * a job's trouble through this rather than matching prefixes themselves,
     * so there is one parser and it is the one that wrote the log.
     *
     * @return list<self>
     */
    public static function parseLog(?string $log): array
    {
        if ($log === null || trim($log) === '') {
            return [];
        }

        return array_map(self::fromLine(...), explode("\n", $log));
    }

    /**
     * The job log line for this note. Ordinary progress reads as plain text;
     * anything that needs a human's attention carries its level, so scanning
     * a two hundred line log for what went wrong works.
     *
     * The one info note that is not plain text is the one whose message
     * begins with a level marker: a course called "[error] gone" would
     * otherwise write a line that reads back as an error VidyaLMS never
     * reported, and a reader counting errors would count it. Writing the
     * "[info] " prefix there is what makes fromLine() a true inverse for
     * every message rather than for most of them.
     */
    public function line(): string
    {
        if ($this->level !== self::INFO) {
            return self::prefix($this->level) . $this->message;
        }

        return self::levelOf($this->message) === null
            ? $this->message
            : self::prefix(self::INFO) . $this->message;
    }

    /**
     * @return array{level: string, message: string}
     */
    public function toArray(): array
    {
        return ['level' => $this->level, 'message' => $this->message];
    }

    /**
     * The level a stored line carries in front of it, or null when it carries
     * none and is an info line by that fact.
     */
    private static function levelOf(string $line): ?string
    {
        foreach (self::LEVELS as $level) {
            if (str_starts_with($line, self::prefix($level))) {
                return $level;
            }
        }

        return null;
    }

    private static function prefix(string $level): string
    {
        return '[' . $level . '] ';
    }

    /**
     * One line of at most that many bytes. Control characters, line breaks
     * and the Unicode line separators become spaces rather than disappearing,
     * so "Tea\n[error] gone" reads as text and not as a second note.
     */
    private static function oneLine(string $value, int $limit): string
    {
        $flattened = preg_replace('/[\x00-\x1F\x7F]|\xC2\x85|\xE2\x80[\xA8\xA9]/', ' ', $value);
        $value = trim(\is_string($flattened) ? $flattened : $value);

        if (\strlen($value) <= $limit) {
            return $value;
        }

        $cut = substr($value, 0, max(0, $limit - \strlen(self::ELLIPSIS)));

        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return rtrim($cut) . self::ELLIPSIS;
    }
}
