<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access\Availability;

/**
 * One stored restriction document: the grammar version and the root node.
 *
 * The document envelope's version versions the tree grammar (operators,
 * show, showc, the leaf envelope); each leaf carries its own version and its
 * own upgrader chain.
 */
final class AvailabilityTree
{
    /** The grammar version this build writes. */
    public const CURRENT_VERSION = 1;

    public function __construct(
        public readonly ?AvailabilityNode $root,
        public readonly int $version = self::CURRENT_VERSION,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->root === null || $this->root->children === [];
    }

    /**
     * Every leaf in the tree, keyed by its path ("c.0.c.2"), which is what
     * availability_rule_refs stores and what the preview route returns.
     *
     * @return array<string, AvailabilityLeaf>
     */
    public function leaves(): array
    {
        if ($this->root === null) {
            return [];
        }

        $found = [];
        self::collect($this->root, '', $found);

        return $found;
    }

    /**
     * A copy of this tree with every leaf's payload rewritten.
     *
     * The tree never interprets a leaf's fields, so the rewrite is the
     * caller's: this only preserves the shape, the operators and the
     * visibility flags around it. It exists because a course copy has to
     * repoint the ids a leaf names without any structure changing, and
     * rebuilding the document by hand at each call site is how a `showc`
     * array gets lost.
     *
     * @param callable(string, array<string, mixed>, int): array<string, mixed> $rewrite type, payload, payload version
     */
    public function mapLeaves(callable $rewrite): self
    {
        return new self($this->root === null ? null : self::rewriteNode($this->root, $rewrite), $this->version);
    }

    /**
     * @param callable(string, array<string, mixed>, int): array<string, mixed> $rewrite
     */
    private static function rewriteNode(AvailabilityNode $node, callable $rewrite): AvailabilityNode
    {
        $children = [];

        foreach ($node->children as $child) {
            $children[] = $child instanceof AvailabilityNode
                ? self::rewriteNode($child, $rewrite)
                : new AvailabilityLeaf(
                    $child->type,
                    $rewrite($child->type, $child->payload, $child->payloadVersion),
                    $child->payloadVersion,
                );
        }

        return new AvailabilityNode($node->op, $children, $node->show, $node->showc);
    }

    /**
     * @param array<string, AvailabilityLeaf> $found
     */
    private static function collect(AvailabilityNode $node, string $path, array &$found): void
    {
        foreach ($node->children as $index => $child) {
            $childPath = ($path === '' ? '' : $path . '.') . 'c.' . $index;

            if ($child instanceof AvailabilityNode) {
                self::collect($child, $childPath, $found);
                continue;
            }

            $found[$childPath] = $child;
        }
    }
}
