<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

/** One closed parser for both legacy bare and registry-owned dotted tokens. */
final class TemplatePlaceholderParser
{
    private const PLACEHOLDER = '/\{([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)\}/i';

    /** @return list<string> */
    public function tokensIn(string $value): array
    {
        $count = preg_match_all(self::PLACEHOLDER, $value, $matches);

        if ($count === false || $count === 0) {
            return [];
        }

        $tokens = [];

        foreach ($matches[1] as $token) {
            $tokens[(string) $token] = true;
        }

        return array_keys($tokens);
    }

    /**
     * Replaces exactly the placeholders present in the original input. A
     * replacement containing braces is data and is never parsed a second
     * time.
     *
     * @param callable(string): ?string $resolve
     *
     * @return array{value:string,used:list<string>,unresolved:list<string>}
     */
    public function replace(string $value, callable $resolve, bool $preserveUnresolved = false): array
    {
        $used = [];
        $unresolved = [];
        $replaced = preg_replace_callback(
            self::PLACEHOLDER,
            static function (array $match) use ($resolve, $preserveUnresolved, &$used, &$unresolved): string {
                $token = (string) $match[1];
                $used[$token] = true;
                $resolved = $resolve($token);

                if ($resolved === null) {
                    $unresolved[$token] = true;

                    return $preserveUnresolved ? (string) $match[0] : '';
                }

                return $resolved;
            },
            $value,
        );

        return [
            'value' => \is_string($replaced) ? $replaced : $value,
            'used' => array_keys($used),
            'unresolved' => array_keys($unresolved),
        ];
    }
}
