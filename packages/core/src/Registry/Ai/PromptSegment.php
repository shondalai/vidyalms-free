<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * One span of a prompt, with where it came from and how far it is trusted.
 *
 * The core never renders a prompt. It hands over segments and the bridge's
 * own mapper renders them under the spotlighting mode its route declares,
 * which makes injection defence a property of the boundary rather than a
 * discipline every task author has to remember.
 *
 * The label names the provenance of the span (`lesson:88`, `submission:412`,
 * `imported:qti`, `fact:item.facility`) and is what the manifest, the dry run
 * and the learner's right-to-know view render.
 */
final class PromptSegment
{
    private function __construct(
        public readonly string $role,
        public readonly PromptTrust $trust,
        public readonly string $label,
        public readonly string $text,
    ) {
    }

    public static function of(string $role, PromptTrust $trust, string $label, string $text): self
    {
        return new self($role, $trust, $label, $text);
    }

    /** The task's own prompt. */
    public static function instruction(string $label, string $text, string $role = 'system'): self
    {
        return new self($role, PromptTrust::TrustedInstruction, $label, $text);
    }

    /** Everything the site did not author itself, which is the default case. */
    public static function content(string $label, string $text, string $role = 'user'): self
    {
        return new self($role, PromptTrust::UntrustedContent, $label, $text);
    }
}
