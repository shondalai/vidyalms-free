<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

final class ResourcePathCases
{
    /** @return array<string, array{string}> */
    public static function authored(): array
    {
        return [
            'lesson upload' => ['lesson-files/1/handout.pdf'],
            'course image' => ['course-images/1/cover.png'],
            'imported file' => ['imports/moodle/1/handout.pdf'],
            'question media' => ['questions/1/diagram.png'],
            'stimulus media' => ['stimuli/1/source.pdf'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function forbidden(): array
    {
        return [
            'answer key export' => ['exports/qti/999/answer-key.zip'],
            'attempt artifact' => ['attempts/999/answer.pdf'],
            'assignment submission' => ['submissions/999/answer.pdf'],
            'certificate' => ['certificates/999.pdf'],
            'unknown prefix' => ['courses/1/handout.pdf'],
            'prefix lookalike' => ['lesson-files-private/secret.pdf'],
            'relative traversal' => ['../secret.pdf'],
            'authored prefix traversal' => ['lesson-files/../exports/secret.zip'],
            'absolute Unix path' => ['/lesson-files/secret.pdf'],
            'absolute Windows path' => ['C:/lesson-files/secret.pdf'],
            'backslash traversal' => ['lesson-files/..\\exports/secret.zip'],
            'remote URL' => ['https://example.test/lesson-files/secret.pdf'],
            'file scheme' => ['file://lesson-files/secret.pdf'],
        ];
    }
}
