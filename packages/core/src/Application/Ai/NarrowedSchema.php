<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

/**
 * A schema reduced to what one route can actually enforce, and the list of
 * everything that reduction gave up.
 *
 * The dropped list is not diagnostic noise. The run flags `schema_unenforced`
 * **naming exactly which constraints the provider did not enforce**, and the
 * item review screen shows that list, so a reviewer looking at a generated
 * item knows whether the option count was constrained by the route or only
 * checked afterwards.
 */
final class NarrowedSchema
{
    /** @var array<string, mixed>|null */
    public readonly ?array $schema;

    /** @var list<string> */
    public readonly array $droppedConstraints;

    /**
     * @param array<string, mixed>|null $schema
     * @param list<string>              $droppedConstraints dotted paths, each ending in the keyword given up
     */
    private function __construct(
        ?array $schema,
        array $droppedConstraints,
    ) {
        $this->schema = $schema;
        $this->droppedConstraints = $droppedConstraints;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $droppedConstraints
     */
    public static function of(array $schema, array $droppedConstraints): self
    {
        return new self($schema, $droppedConstraints);
    }

    /**
     * The answer for a route whose bridge declares no constrained decoding at
     * all: nothing is sent as guidance, and everything the original schema
     * said is validated after the fact instead.
     *
     * @param list<string> $droppedConstraints
     */
    public static function unenforced(array $droppedConstraints): self
    {
        return new self(null, $droppedConstraints);
    }

    /**
     * Whether the route enforced everything the type's own schema asked for.
     * False is the ordinary answer and is never an error.
     */
    public function isFullyEnforced(): bool
    {
        return $this->schema !== null && $this->droppedConstraints === [];
    }
}
