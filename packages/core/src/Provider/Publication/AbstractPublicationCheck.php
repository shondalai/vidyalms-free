<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Publication;

use Shondalai\VidyaLMS\Core\Port\I18n\NeutralStrings;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationCheckInterface;

/**
 * The label every free check shares, and the definition walk most of them
 * need.
 *
 * A check reads the context and nothing else: the questions, the frozen
 * policy and the quiz settings are all resolved once before the checks run,
 * because a check that opened a query would run one per question on every
 * save.
 */
abstract class AbstractPublicationCheck implements PublicationCheckInterface
{
    public function __construct(protected readonly ?TranslatorPort $translator = null)
    {
    }

    public function label(): string
    {
        return NeutralStrings::say($this->translator, self::labelKey($this->key()));
    }

    public static function labelKey(string $checkKey): string
    {
        return 'vidyalms.publication.check.' . $checkKey;
    }

    /**
     * Every value stored under a name anywhere in a definition, however deep.
     *
     * A shallow, name-keyed walk rather than a type-aware read on purpose:
     * a check must work with the provider that authored the definition
     * uninstalled, so it cannot ask the type where its own fractions live.
     *
     * @param array<array-key, mixed> $node
     *
     * @return list<mixed>
     */
    final protected function valuesNamed(array $node, string $name): array
    {
        $found = [];

        /** @var mixed $value */
        foreach ($node as $key => $value) {
            if ($key === $name) {
                $found[] = $value;
            }

            if (\is_array($value)) {
                foreach ($this->valuesNamed($value, $name) as $nested) {
                    $found[] = $nested;
                }
            }
        }

        return $found;
    }
}
