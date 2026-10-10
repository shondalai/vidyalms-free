<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Upload;

use Shondalai\VidyaLMS\Core\Http\ApiProblem;

/** A bounded read after the host has verified is_uploaded_file(). */
final class UploadedBytes
{
    public static function read(string $temporary, int $maximum): string
    {
        if ($maximum <= 0) {
            throw new \InvalidArgumentException('An upload requires a positive byte ceiling.');
        }
        $size = filesize($temporary);
        if ($size === false || $size <= 0) {
            throw ApiProblem::validation(['file' => 'The uploaded file is empty or unreadable.']);
        }
        if ($size > $maximum) {
            throw new ApiProblem(413, 'Payload too large', 'The uploaded file exceeds the upload limit.');
        }
        $contents = file_get_contents($temporary, false, null, 0, $maximum + 1);
        if ($contents === false) {
            throw ApiProblem::validation(['file' => 'The uploaded file could not be read.']);
        }
        if (strlen($contents) > $maximum) {
            throw new ApiProblem(413, 'Payload too large', 'The uploaded file exceeds the upload limit.');
        }
        return $contents;
    }
}
