<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ContributesToCredential;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;

/**
 * What the credential is for, and the achievement name the credential body
 * carries.
 */
final class CourseNameElement extends TokenTextElement implements ContributesToCredential
{
    public function key(): string
    {
        return 'course_name';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_IDENTITY;
    }

    public function order(): int
    {
        return 20;
    }

    public function credentialProperty(): string
    {
        return 'credentialSubject.achievement.name';
    }

    protected function tokenName(): string
    {
        return 'course.title';
    }

    protected function defaultSize(): int
    {
        return 26;
    }
}
