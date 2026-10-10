<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\SchemaVersionRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Records that a schema version has been applied. Installers may run their
 * scripts repeatedly; the version row is written once and stays put.
 */
final class RecordSchemaVersion
{
    public function __construct(
        private readonly SchemaVersionRepository $versions,
    ) {
    }

    public function execute(string $version): void
    {
        if (trim($version) === '') {
            throw ValidationException::forField('version', 'A schema version is required.');
        }

        $this->versions->recordOnce($version);
    }
}
