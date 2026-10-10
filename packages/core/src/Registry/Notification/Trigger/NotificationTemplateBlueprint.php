<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Trigger;

/**
 * A provider-owned starting point for one notification identity.
 *
 * The source remains an array here so the registry layer does not depend on
 * an application value object. NotificationTemplateCompiler is the one
 * boundary that decodes it into the canonical TemplateSource shape.
 */
final class NotificationTemplateBlueprint
{
    /**
     * @param array<string, mixed>  $source
     * @param array<string, string> $syntheticValues values keyed by exact dotted or bare placeholder name
     * @param list<string>          $requiredTokens
     */
    public function __construct(
        public readonly int $version,
        public readonly array $source,
        public readonly array $syntheticValues,
        public readonly array $requiredTokens,
        public readonly bool $generic = false,
    ) {
        if ($version < 1) {
            throw new \InvalidArgumentException('A notification template blueprint version must be positive.');
        }

        foreach ($syntheticValues as $token => $value) {
            if (!\is_string($token) || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/D', $token) !== 1) {
                throw new \InvalidArgumentException('Synthetic notification values must use valid placeholder names.');
            }

            if (!\is_string($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'Synthetic notification value "%s" must be text.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $token,
                ));
            }
        }

        foreach ($requiredTokens as $token) {
            if (!\is_string($token) || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/D', $token) !== 1) {
                throw new \InvalidArgumentException('Required notification tokens must use valid placeholder names.');
            }

            if (!isset($syntheticValues[$token]) || trim($syntheticValues[$token]) === '') {
                throw new \InvalidArgumentException(sprintf(
                    'Required notification token "%s" needs a synthetic value.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $token,
                ));
            }
        }
    }
}
