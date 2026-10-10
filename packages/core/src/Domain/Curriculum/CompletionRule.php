<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Curriculum;

enum CompletionRule: string
{
    case AllLessons = 'all_lessons';
    case Percentage = 'percentage';
    case PassFinal = 'pass_final';
}
