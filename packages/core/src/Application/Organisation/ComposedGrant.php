<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

/**
 * What passing both halves of the composed rule produced: the tenant scope and
 * the release record that let this organisation reach this course.
 *
 * It carries the release row rather than a bare boolean because the row's own
 * end date bounds any grant made under it, and a caller that had to re-read it
 * would be asking the same question twice with a window in between.
 */
final class ComposedGrant
{
    /** @param array<string, mixed> $release */
    private function __construct(
        public readonly OrgScope $scope,
        public readonly array $release,
    ) {
    }

    /** @param array<string, mixed> $release */
    public static function of(OrgScope $scope, array $release): self
    {
        return new self($scope, $release);
    }

    /**
     * When this release stops, or null when it does not.
     *
     * The one thing every caller reads off the row, named once here so three
     * use cases stop spelling the column.
     */
    public function endsAt(): ?string
    {
        $ends = $this->release['ends_at'] ?? null;

        return $ends === null ? null : (string) $ends;
    }
}
