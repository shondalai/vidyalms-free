<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * One advisory integrity signal recorded against an attempt.
 *
 * Evidence, never enforcement. Window blur and page visibility changes have a
 * documented false positive rate against legitimate access tools: docked and
 * separate-window magnifiers, on-screen keyboards, speech recognition
 * correction windows and separate dictionary or read-aloud applications all
 * blur the browser. So nothing in the core acts on one of these rows, no
 * answer, score or attempt state is affected by recording one, and the actor
 * is always the system rather than a person.
 *
 * No kind may ever be derived from correctness. A signal is about what the
 * browser did, not about what the learner answered.
 */
final class IntegrityEvent
{
    public const ACTOR_SYSTEM = 'system';

    public const KIND_WINDOW_BLUR = 'window_blur';
    public const KIND_WINDOW_FOCUS = 'window_focus';
    public const KIND_VISIBILITY_HIDDEN = 'visibility_hidden';
    public const KIND_VISIBILITY_VISIBLE = 'visibility_visible';
    public const KIND_PASTE = 'paste';
    public const KIND_COPY = 'copy';
    public const KIND_FULLSCREEN_EXIT = 'fullscreen_exit';
    public const KIND_NAVIGATION_AWAY = 'navigation_away';

    /** @var array<string, mixed>|null */
    public readonly ?array $context;

    /**
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $attemptId,
        public readonly string $kind,
        public readonly \DateTimeImmutable $occurredAt,
        ?array $context = null,
        public readonly string $actor = self::ACTOR_SYSTEM,
    ) {
        $this->context = $context;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->attemptId, $this->kind, $this->occurredAt, $this->context, $this->actor);
    }

    /**
     * The vocabulary the free build ships.
     *
     * @return list<string>
     */
    public static function freeKinds(): array
    {
        return [
            self::KIND_WINDOW_BLUR,
            self::KIND_WINDOW_FOCUS,
            self::KIND_VISIBILITY_HIDDEN,
            self::KIND_VISIBILITY_VISIBLE,
            self::KIND_PASTE,
            self::KIND_COPY,
            self::KIND_FULLSCREEN_EXIT,
            self::KIND_NAVIGATION_AWAY,
        ];
    }

    /**
     * A kind is either one of the free vocabulary or a vendor-namespaced
     * extension, so a package contributes a signal without a core edit and
     * without being able to collide with the shipped names.
     */
    public static function isKnownKind(string $kind): bool
    {
        if (\in_array($kind, self::freeKinds(), true)) {
            return true;
        }

        return preg_match('/^x_[a-z0-9]+_[a-z0-9_]+$/', $kind) === 1;
    }
}
