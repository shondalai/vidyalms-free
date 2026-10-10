<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiRedactorInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\PromptSegment;
use Shondalai\VidyaLMS\Core\Registry\Ai\PromptTrust;
use Shondalai\VidyaLMS\Core\Registry\Ai\RedactedPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\AiRedactionContext;

/**
 * The shipped default redactor, and the only path from a resolved payload to
 * anything that may cross a model boundary.
 *
 * Two duties land in this one testable class, deliberately. It strips or
 * pseudonymises identifiers under the active policy's redaction level, and it
 * applies the trust marking to untrusted segments. Reimplementing either per
 * task is how one of them eventually gets forgotten.
 *
 * **The manifest enumerates one entry per emitted segment**, in emission
 * order, so the manifest and the wire cannot disagree. A conformance test
 * asserts exactly that per task: the set of manifest entries equals the set
 * of PromptSegment labels on the request that was sent. That is what the
 * learner's right-to-know view and the dry run render, and a redactor that
 * could not produce it would be redacting nothing anybody can later be shown.
 *
 * **Nothing this class emits is ever tagged `trusted_instruction`.** The
 * payload is content: lesson bodies, transcripts, submissions, imported
 * material, fact values and authored options, every one of it authored by
 * somebody the hostile-input inventory names. A chunk arriving with a claim
 * to be a trusted instruction is downgraded rather than believed, because the
 * containment story rests on the marking and the marking must not be
 * defeasible by a field somebody else fills in. The task's own prompt is the
 * only trusted instruction there is, and it does not travel through here.
 *
 * **A fact the task did not declare is dropped rather than sent.** The
 * context carries the declared keys and never the values, so this is a set
 * membership test on a list the registration-time check already validated:
 * a task cannot widen at run time what it declared at registration.
 */
final class StrictRedactor implements AiRedactorInterface
{
    public const KEY = 'strict';

    /**
     * The levels this provider answers to. An unrecognised level is treated
     * as STRICT rather than as OFF, because a redaction level is a gate and
     * an unresolved gate fails closed.
     */
    public const LEVEL_STRICT = 'strict';
    public const LEVEL_STANDARD = 'standard';

    /** What a stripped identifier is replaced with, so the shape stays readable. */
    public const MASK_EMAIL = '[redacted:email]';
    public const MASK_URL = '[redacted:url]';
    public const MASK_NUMBER = '[redacted:number]';

    /**
     * The option name the author's own free instruction arrives under.
     *
     * It is the one payload entry marked `author_instruction`, which is a
     * third trust level and deliberately not a promotion to trusted: this
     * document's own hostile-input inventory begins with course content
     * authored by anyone with instructor rights, and calling the free
     * instruction field trusted hands exactly that population the one segment
     * able to cancel the standing instruction containing everything else.
     */
    public const AUTHOR_INSTRUCTION_OPTION = 'instruction';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.redactor.strict.label';
    }

    public function redact(AiTaskPayload $payload, AiRedactionContext $context): RedactedPayload
    {
        $level = $context->redactionLevel === self::LEVEL_STANDARD ? self::LEVEL_STANDARD : self::LEVEL_STRICT;
        $segments = [];

        foreach ($payload->chunks as $chunk) {
            $text = $this->scrub($chunk['text'], $level);

            if ($text === '') {
                continue;
            }

            $segments[] = PromptSegment::of(
                'user',
                $this->trustFor($chunk['trust']),
                $chunk['provenance'],
                $text,
            );
        }

        foreach ($payload->facts as $key => $value) {
            $key = (string) $key;

            if (!$context->declares($key)) {
                continue;
            }

            $text = $this->scrub($this->render($value), $level);

            if ($text === '') {
                continue;
            }

            $segments[] = PromptSegment::content('fact:' . $key, $text);
        }

        foreach ($payload->options as $name => $value) {
            $name = (string) $name;
            $text = $this->scrub($this->render($value), $level);

            if ($text === '') {
                continue;
            }

            $segments[] = $name === self::AUTHOR_INSTRUCTION_OPTION
                ? PromptSegment::of('user', PromptTrust::AuthorInstruction, 'option:' . $name, $text)
                : PromptSegment::content('option:' . $name, $text);
        }

        // The manifest is the segment labels and is derived rather than
        // restated, so this class cannot drift from the invariant the value
        // object now enforces for every redactor.
        return RedactedPayload::ofSegments($segments, self::KEY, $level);
    }

    /**
     * Never up, only down. A chunk claiming to be a trusted instruction is
     * content that says so, which is the attack rather than the exception.
     */
    private function trustFor(string $declared): PromptTrust
    {
        return $declared === PromptTrust::AuthorInstruction->value
            ? PromptTrust::AuthorInstruction
            : PromptTrust::UntrustedContent;
    }

    /**
     * Everything this class knows how to recognise deterministically.
     *
     * It is a small list on purpose. A redactor that claimed to find every
     * personal name in free prose would be making a promise no expression can
     * keep, and a site needing more registers its own provider rather than
     * forking this one: that is what the registry is for.
     */
    private function scrub(string $text, string $level): string
    {
        $text = (string) preg_replace(
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            self::MASK_EMAIL,
            $text,
        );

        if ($level === self::LEVEL_STRICT) {
            $text = (string) preg_replace('#\bhttps?://\S+#i', self::MASK_URL, $text);
            $text = (string) preg_replace('/\b\d{7,}\b/', self::MASK_NUMBER, $text);
        }

        return trim($text);
    }

    /**
     * A value becomes one span or it does not travel.
     *
     * Arrays are rendered as their scalar leaves joined by a separator rather
     * than as JSON, because a structure crossing the boundary is a structure
     * a manifest entry cannot honestly describe: the entry names the fact,
     * and the fact has to be one thing a person can be shown.
     */
    private function render(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (\is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                $rendered = $this->render($item);

                if ($rendered !== '') {
                    $parts[] = $rendered;
                }
            }

            return implode("\n", $parts);
        }

        return '';
    }
}
