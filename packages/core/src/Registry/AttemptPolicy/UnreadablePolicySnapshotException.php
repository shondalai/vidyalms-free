<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\AttemptPolicy;

/**
 * A frozen policy snapshot written by a newer build than this one.
 *
 * Refused rather than read under this build's meanings. The snapshot is
 * permanent evidence: review, appeal, regrade and item analysis all read it
 * for the life of the row, so interpreting a shape whose members may have
 * changed meaning would move marks silently and leave nothing to detect it
 * by. That is the same gate the definition version keeps, for the same
 * reason.
 */
final class UnreadablePolicySnapshotException extends \RuntimeException
{
    public function __construct(
        public readonly int $storedVersion,
        public readonly int $supportedVersion,
    ) {
        parent::__construct(sprintf(
            'This attempt was recorded in policy snapshot version %d, and this build reads version %d. '
            . 'Upgrade before reading it: interpreting a newer snapshot under older meanings would move marks.',
            $storedVersion,
            $supportedVersion,
        ));
    }
}
