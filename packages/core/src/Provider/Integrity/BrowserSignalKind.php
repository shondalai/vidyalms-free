<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Integrity;

use Shondalai\VidyaLMS\Core\Domain\Assessment\IntegrityEvent;
use Shondalai\VidyaLMS\Core\Port\I18n\NeutralStrings;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Integrity\IntegrityEventKindInterface;

/**
 * The eight observations a browser can make about an attempt, and every one
 * of them is advisory.
 *
 * **All eight are suppressed for a learner whose accessibility profile
 * declares a relevant need, and that is not caution, it is the documented
 * false positive rate.** Separate-window and docked magnifiers, on-screen
 * keyboards, speech recognition correction windows, and separate dictionary
 * or read-aloud applications all blur the browser and all hide the page; a
 * magnifier forces an exit from fullscreen; a speech-to-text tool pastes.
 * Every one of those is a legitimate access tool, and a ledger that recorded
 * their use as suspicion would be evidence against the learners the tools
 * exist to serve.
 *
 * There is deliberately no unsuppressed kind here. A signal that is genuinely
 * unambiguous comes from a proctoring integration that observed something the
 * browser cannot see, and that package registers its own kind with its own
 * answer to this question.
 */
final class BrowserSignalKind implements IntegrityEventKindInterface
{
    public const LABEL_PREFIX = 'vidyalms.integrity.kind.';

    public function __construct(
        private readonly string $kind,
        private readonly ?TranslatorPort $translator = null,
    ) {
    }

    /**
     * One provider per kind, from the vocabulary the free build ships.
     *
     * @return list<self>
     */
    public static function all(?TranslatorPort $translator = null): array
    {
        return array_map(
            static fn (string $kind): self => new self($kind, $translator),
            IntegrityEvent::freeKinds(),
        );
    }

    public function key(): string
    {
        return $this->kind;
    }

    public function label(): string
    {
        return NeutralStrings::say($this->translator, self::LABEL_PREFIX . $this->kind);
    }

    public function suppressedByAccessibilityProfile(): bool
    {
        return true;
    }

    /**
     * Only the facts an instructor can act on: how long the attempt was
     * away, and which question and screen were in front of the learner.
     *
     * An allowlist rather than a passthrough, because the payload arrives
     * from the learner's own browser: a context column that accepted
     * whatever was posted would let a client write arbitrary content into an
     * audit table that a human is going to read as evidence.
     *
     * @var list<string>
     */
    private const ALLOWED = ['duration_ms', 'seconds_hidden', 'question_id', 'screen'];

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalise(array $context): array
    {
        $normalised = [];

        foreach (self::ALLOWED as $name) {
            $value = $context[$name] ?? null;

            if (\is_int($value) || (\is_string($value) && is_numeric($value))) {
                $normalised[$name] = max(0, (int) $value);
            }
        }

        return $normalised;
    }
}
