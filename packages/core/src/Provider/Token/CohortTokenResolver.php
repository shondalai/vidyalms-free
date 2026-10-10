<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Token;

use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

/**
 * The run a message is about, read from what the trigger already resolved.
 *
 * It owns the prefix in the free build and answers null for every token when
 * no run is in the parameter bag, which leaves the placeholder intact. That
 * is deliberate: the prefix is claimed here so a free template can carry a
 * run placeholder and a paid package that adds runs answers it by replacing
 * this provider rather than by fighting it for the prefix. Two packages
 * claiming one prefix would otherwise fail at registration, and the free
 * build would be the one that had to move.
 */
final class CohortTokenResolver implements TokenResolverInterface
{
    public const PREFIX = 'cohort';

    public function key(): string
    {
        return self::PREFIX;
    }

    public function prefix(): string
    {
        return self::PREFIX;
    }

    public function label(): string
    {
        return 'vidyalms.token.cohort';
    }

    public function tokens(): array
    {
        return ['cohort.title', 'cohort.code', 'cohort.starts_at', 'cohort.ends_at'];
    }

    public function resolve(string $token, TokenContext $context): ?string
    {
        return match ($token) {
            'cohort.title' => $context->string('cohort_title'),
            'cohort.code' => $context->string('cohort_code'),
            'cohort.starts_at' => $context->string('cohort_starts_at'),
            'cohort.ends_at' => $context->string('cohort_ends_at'),
            default => null,
        };
    }
}
