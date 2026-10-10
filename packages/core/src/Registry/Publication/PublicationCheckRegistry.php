<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Publication;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * The publication checks this site runs.
 *
 * The miss is absence, which the design states directly: an unresolved
 * publication check is simply not run. There is no stored key to keep
 * resolvable here, because nothing writes a check key into a row; losing a
 * package loses its advice and nothing else.
 *
 * @extends AbstractProviderRegistry<PublicationCheckInterface>
 */
final class PublicationCheckRegistry extends AbstractProviderRegistry
{
    /** The string a check that threw is reported under. */
    public const CHECK_FAILED = 'vidyalms.publication.check_failed';

    protected function providerInterface(): string
    {
        return PublicationCheckInterface::class;
    }

    /**
     * Every finding from every registered check, in registration order.
     *
     * A check that throws is not allowed to take the transition down with
     * it: an author saving a quiz must not be blocked because a third-party
     * check has a bug in it, and a check whose own failure silently passed
     * the quiz would be worse. So the failure becomes a warning naming the
     * check, which is visible to the author and to support.
     *
     * @return list<PublicationFinding>
     */
    public function runAll(PublicationContext $context): array
    {
        $findings = [];

        foreach ($this->all() as $check) {
            try {
                foreach ($check->run($context) as $finding) {
                    $findings[] = $finding;
                }
            } catch (\Throwable $failure) {
                $findings[] = PublicationFinding::warning(
                    'check_failed',
                    self::CHECK_FAILED,
                    ['check' => $check->key(), 'reason' => $failure->getMessage()],
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<PublicationFinding> $findings
     *
     * @return list<PublicationFinding>
     */
    public static function blocking(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (PublicationFinding $finding): bool => $finding->isBlocking(),
        ));
    }
}
