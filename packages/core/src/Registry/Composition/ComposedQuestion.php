<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Composition;

/**
 * One question a composition put into an attempt, with whatever the
 * composition decided about it.
 *
 * Everything but the question id is an override, and every override is null
 * for the free default, whose whole job is to serve the quiz's own
 * published questions exactly as it always did. A slot supplies the points
 * because QTI puts weight on the item reference rather than on the item, so
 * the same banked question is worth two marks in a formative quiz and five
 * in a final.
 *
 * The selection record is the audit: which slot produced this row, from
 * which pool, at which version. It is written to the frozen manifest with
 * the question, because a draw nobody can reconstruct is a draw nobody can
 * defend on appeal.
 */
final class ComposedQuestion
{
    /** @var array<string, mixed>|null */
    public readonly ?array $selection;

    /**
     * @param array<string, mixed>|null $selection
     */
    /** @var array<string, mixed> */
    public readonly array $policyParams;

    /**
     * @param array<string, mixed>|null $selection
     * @param array<string, mixed>      $policyParams
     */
    public function __construct(
        public readonly int $questionId,
        public readonly ?int $questionVersionId = null,
        public readonly ?float $points = null,
        public readonly ?bool $scored = null,
        ?array $selection = null,
        /**
         * The marking rule this placement overrides the question's own with.
         *
         * A first-class member beside points rather than a key inside the
         * audit blob, because the resolution order the design states is
         * slot, then question, then definition, and a value only the audit
         * carries is a value nothing reads. It was stored on the slot,
         * written into the audit and then never applied, so an author who
         * placed a banked item in a final exam under plus_minus while the
         * item's own row said per_target had every learner marked under
         * per_target with nothing warning. It is also why item statistics
         * key on (version, policy) rather than on the version alone.
         */
        public readonly ?string $policyKey = null,
        array $policyParams = [],
    ) {
        $this->selection = $selection;
        $this->policyParams = $policyParams;
    }
}
