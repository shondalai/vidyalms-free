<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Token;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<TokenResolverInterface>
 */
final class TokenResolverRegistry extends AbstractProviderRegistry
{
    public const EXTENSION_POINT = 'token_resolver';

    protected function providerInterface(): string
    {
        return TokenResolverInterface::class;
    }

    /**
     * Every placeholder every registered resolver answers, sorted, which is
     * what the insert menu offers and what the save-time validation checks a
     * template body against.
     *
     * @return list<string>
     */
    public function vocabulary(): array
    {
        $tokens = [];

        foreach ($this->all() as $resolver) {
            foreach ($resolver->tokens() as $token) {
                $tokens[$token] = true;
            }
        }

        $names = array_keys($tokens);
        sort($names);

        return $names;
    }

    /**
     * The resolver owning a placeholder, by its prefix, or null when nothing
     * claims it.
     */
    public function resolverFor(string $token): ?TokenResolverInterface
    {
        $dot = strpos($token, '.');
        $prefix = $dot === false ? $token : substr($token, 0, $dot);

        foreach ($this->all() as $resolver) {
            if ($resolver->prefix() === $prefix) {
                return $resolver;
            }
        }

        return null;
    }
}
