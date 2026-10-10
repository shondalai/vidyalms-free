<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Pdf;

/** Mechanical, local-only rendering. It never accepts authored HTML or URLs. */
interface PdfPort
{
    public function render(PdfDocument $document): string;

    /** @return array<string, mixed> Immutable engine/font/grammar identity. */
    public function provenance(): array;
}
