<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access\Availability;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The one reader and writer of the stored tree document.
 *
 * Structural faults are refused here rather than at evaluation, because a
 * tree that reaches the table through the API, an importer or a course copy
 * would otherwise evaluate to blocked forever and sit in the denominator. A
 * c and showc length mismatch is refused by name: it is the single most
 * reported structural fault in the model this grammar is taken from.
 */
final class AvailabilityTreeCodec
{
    /** How deep a tree may nest, so a hand-built document cannot exhaust the stack. */
    public const MAX_DEPTH = 12;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>|null $document the StoredShape envelope as stored
     */
    public static function decode(?array $document): AvailabilityTree
    {
        if ($document === null || $document === []) {
            return new AvailabilityTree(null);
        }

        $version = StoredShape::versionOf($document);
        $body = StoredShape::bodyOf($document);

        if ($body === []) {
            return new AvailabilityTree(null, $version === StoredShape::UNVERSIONED ? 1 : $version);
        }

        return new AvailabilityTree(
            self::node($body, '', 0),
            $version === StoredShape::UNVERSIONED ? 1 : $version,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function encode(AvailabilityTree $tree): ?array
    {
        if ($tree->root === null) {
            return null;
        }

        return StoredShape::wrap(self::nodeToArray($tree->root), $tree->version);
    }

    /**
     * The wire shape, which is the stored shape without the envelope.
     *
     * @return array<string, mixed>|null
     */
    public static function toArray(AvailabilityTree $tree): ?array
    {
        return $tree->root === null ? null : self::nodeToArray($tree->root);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function node(array $raw, string $path, int $depth): AvailabilityNode
    {
        $field = $path === '' ? 'tree' : $path;

        if ($depth > self::MAX_DEPTH) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'The restriction tree nests too deeply.');
        }

        $op = $raw['op'] ?? null;

        if (!\is_string($op) || !\in_array($op, AvailabilityNode::operators(), true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field . '.op', 'Unknown restriction operator.');
        }

        $children = $raw['c'] ?? null;

        if (!\is_array($children) || !array_is_list($children)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field . '.c', 'A restriction node needs a list of children.');
        }

        $parsed = [];

        foreach ($children as $index => $child) {
            $childPath = ($path === '' ? '' : $path . '.') . 'c.' . $index;

            if (!\is_array($child)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($childPath, 'A restriction child must be a node or a condition.');
            }

            /** @var array<string, mixed> $child */
            $parsed[] = isset($child['op'])
                ? self::node($child, $childPath, $depth + 1)
                : self::leaf($child, $childPath);
        }

        $showc = [];
        $rawShowc = $raw['showc'] ?? null;

        if ($rawShowc !== null) {
            if (!\is_array($rawShowc) || !array_is_list($rawShowc)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field . '.showc', 'showc must be a list of booleans.');
            }

            foreach ($rawShowc as $flag) {
                $showc[] = (bool) $flag;
            }
        }

        $usesPerChild = $op === AvailabilityNode::OP_AND || $op === AvailabilityNode::OP_NOT_OR;

        // The refusal this grammar exists to make loudly. Under and and
        // not_or a failure identifies which child failed, so hiding is a
        // per-child decision and the arrays have to line up.
        if ($usesPerChild && $showc !== [] && \count($showc) !== \count($parsed)) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field . '.showc',
                sprintf(
                    'showc has %d entries for %d children; they must be the same length.',
                    \count($showc),
                    \count($parsed),
                ),
            );
        }

        if (!$usesPerChild && $showc !== []) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field . '.showc',
                'showc applies only under "and" and "not_or"; use show for "or" and "not_and".',
            );
        }

        return new AvailabilityNode($op, $parsed, (bool) ($raw['show'] ?? true), $showc);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function leaf(array $raw, string $path): AvailabilityLeaf
    {
        $type = $raw['type'] ?? null;

        if (!\is_string($type) || $type === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($path . '.type', 'A condition needs a provider key.');
        }

        $payload = $raw['payload'] ?? [];

        if (!\is_array($payload)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($path . '.payload', 'A condition payload must be an object.');
        }

        /** @var array<string, mixed> $payload */
        $version = StoredShape::versionOf($payload);
        $preset = $raw['preset'] ?? null;

        return new AvailabilityLeaf(
            $type,
            StoredShape::bodyOf($payload),
            $version === StoredShape::UNVERSIONED ? 1 : $version,
            \is_string($preset) && $preset !== '' ? $preset : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function nodeToArray(AvailabilityNode $node): array
    {
        $children = [];

        foreach ($node->children as $child) {
            $children[] = $child instanceof AvailabilityNode
                ? self::nodeToArray($child)
                : self::leafToArray($child);
        }

        $out = ['op' => $node->op, 'c' => $children];

        if ($node->usesPerChildVisibility()) {
            if ($node->showc !== []) {
                $out['showc'] = $node->showc;
            }
        } else {
            $out['show'] = $node->show;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function leafToArray(AvailabilityLeaf $leaf): array
    {
        $out = [
            'type' => $leaf->type,
            'payload' => StoredShape::wrap($leaf->payload, $leaf->payloadVersion),
        ];

        if ($leaf->preset !== null) {
            $out['preset'] = $leaf->preset;
        }

        return $out;
    }
}
