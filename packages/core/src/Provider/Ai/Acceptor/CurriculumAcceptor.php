<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Acceptor;

use Shondalai\VidyaLMS\Core\Application\Curriculum\AddAuthoredLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddModule;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Ai\AcceptedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraft;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftAcceptorInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\AiAcceptContext;

/**
 * The three curriculum draft kinds, each written through the use case the
 * authoring screens already call.
 *
 * One class with three factories, because the shape they share is the shape
 * that matters: a draft names its parent, the parent's own authorisation is
 * checked by the use case, and the acceptor returns an identity rather than
 * an entity. Three files repeating that would be three places for the
 * authorisation to be forgotten in.
 *
 * `course_outline` writes the module and then its lessons in one pass, which
 * is what makes the free structural build worth accepting: the outline the
 * headings produced becomes a real module with real lessons, and the
 * transaction boundary around all of it belongs to the accept use case rather
 * than here.
 */
final class CurriculumAcceptor implements AiDraftAcceptorInterface
{
    public const LESSON = 'lesson';
    public const MODULE = 'module';
    public const COURSE_OUTLINE = 'course_outline';

    public const PAYLOAD_COURSE_ID = 'course_id';
    public const PAYLOAD_MODULE_ID = 'module_id';

    /** The lesson type a drafted body is written as. */
    public const LESSON_TYPE = 'text';

    private function __construct(
        private readonly string $key,
        private readonly AddModule $addModule,
        private readonly AddAuthoredLesson $addLesson,
    ) {
    }

    public static function lesson(AddModule $addModule, AddAuthoredLesson $addLesson): self
    {
        return new self(self::LESSON, $addModule, $addLesson);
    }

    public static function module(AddModule $addModule, AddAuthoredLesson $addLesson): self
    {
        return new self(self::MODULE, $addModule, $addLesson);
    }

    public static function courseOutline(AddModule $addModule, AddAuthoredLesson $addLesson): self
    {
        return new self(self::COURSE_OUTLINE, $addModule, $addLesson);
    }

    /**
     * @return list<self>
     */
    public static function all(AddModule $addModule, AddAuthoredLesson $addLesson): array
    {
        return [
            self::lesson($addModule, $addLesson),
            self::module($addModule, $addLesson),
            self::courseOutline($addModule, $addLesson),
        ];
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'ai.acceptor.' . $this->key . '.label';
    }

    public function accept(AiDraft $draft, AiAcceptContext $context): AcceptedArtefact
    {
        return match ($this->key) {
            self::LESSON => $this->acceptLesson($draft, $context),
            default => $this->acceptModule($draft, $context),
        };
    }

    private function acceptLesson(AiDraft $draft, AiAcceptContext $context): AcceptedArtefact
    {
        $moduleId = $this->wholeNumber($draft, self::PAYLOAD_MODULE_ID);
        $lesson = $this->addLesson->execute(
            $context->accepter,
            $moduleId,
            $this->title($draft),
            self::LESSON_TYPE,
            $this->content($draft),
            null,
            $this->summary($draft),
        );

        return AcceptedArtefact::of(
            $context->targetExtensionPoint,
            $context->targetProviderKey,
            'lesson',
            $lesson->lesson->id,
            ['module_id' => $moduleId],
        );
    }

    private function acceptModule(AiDraft $draft, AiAcceptContext $context): AcceptedArtefact
    {
        $courseId = $this->wholeNumber($draft, self::PAYLOAD_COURSE_ID);
        $module = $this->addModule->execute(
            $context->accepter,
            $courseId,
            $this->title($draft),
            $this->summary($draft),
        );

        $moduleId = $module->id;
        $lessons = 0;

        if ($this->key === self::COURSE_OUTLINE && $moduleId > 0) {
            foreach ($this->lessonTitles($draft) as $title) {
                $this->addLesson->execute($context->accepter, $moduleId, $title, self::LESSON_TYPE);
                ++$lessons;
            }
        }

        return AcceptedArtefact::of(
            $context->targetExtensionPoint,
            $context->targetProviderKey,
            'module',
            $moduleId,
            ['course_id' => $courseId, 'lessons_created' => $lessons],
        );
    }

    /**
     * @return list<string>
     */
    private function lessonTitles(AiDraft $draft): array
    {
        $lessons = $draft->payload['lessons'] ?? null;

        if (!\is_array($lessons)) {
            return [];
        }

        $titles = [];

        foreach ($lessons as $lesson) {
            $title = \is_array($lesson) ? ($lesson['title'] ?? null) : $lesson;

            if (\is_string($title) && trim($title) !== '') {
                $titles[] = trim($title);
            }
        }

        return $titles;
    }

    private function title(AiDraft $draft): string
    {
        $title = $draft->payload['title'] ?? null;

        if (!\is_string($title) || trim($title) === '') {
            throw ValidationException::forField('title', 'An accepted curriculum draft needs a title.');
        }

        return trim($title);
    }

    private function summary(AiDraft $draft): ?string
    {
        $summary = $draft->payload['summary'] ?? null;

        return \is_string($summary) && $summary !== '' ? $summary : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(AiDraft $draft): array
    {
        $content = $draft->payload['content'] ?? null;

        if (\is_array($content)) {
            /** @var array<string, mixed> $content */
            return $content;
        }

        $body = $draft->payload['body'] ?? null;

        return \is_string($body) ? ['body' => $body] : [];
    }

    private function wholeNumber(AiDraft $draft, string $field): int
    {
        $value = $draft->payload[$field] ?? null;

        if (!\is_int($value) || $value <= 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'An accepted curriculum draft names its parent.');
        }

        return $value;
    }
}
