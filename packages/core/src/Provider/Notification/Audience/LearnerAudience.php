<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Audience;

use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;

/**
 * The event's own subject: the one person the occurrence is about.
 *
 * It respects a visibility scope trivially, because it resolves to one
 * learner the caller already named.
 */
final class LearnerAudience implements AudienceResolverInterface, FiltersByVisibility
{
    public const KEY = 'learner';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.audience.learner';
    }

    public function resolve(AudienceContext $context): iterable
    {
        if ($context->learnerUserId !== null && $context->learnerUserId > 0) {
            yield $context->learnerUserId;
        }
    }
}
