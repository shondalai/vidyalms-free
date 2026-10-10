<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Registry\Context\IssuanceParameterContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\ContributesIssuanceParameters;
use Shondalai\VidyaLMS\Core\Registry\Token\DeclaresUnfrozenTokens;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverRegistry;

/**
 * Every placeholder value one certificate render needs, resolved once.
 *
 * This deletes a hard-coded array, and it deletes the second one it had
 * become. The render path assembled five context keys inline; the fix moved
 * them into a static method with a closed fourteen-argument signature, which
 * is the same closed list one file further on. Four of the shipped token
 * families read keys that method never wrote, so they could not resolve at
 * all, and the paid credit table rendered empty on every real issuance while
 * passing a unit test that handed it a bag built by hand.
 *
 * So the bag is assembled from two sources: the facts of the issuance itself,
 * which belong to no family, and one contribution per registered resolver
 * that implements ContributesIssuanceParameters. A package that adds an
 * eighth family supplies its own inputs and no core file changes.
 *
 * The bag is resolved at issue and frozen onto the row. A render reads the
 * frozen values and never resolves again, which is what makes a certificate
 * immune to a course being renamed in 2028.
 */
final class CertificateTokens
{
    /**
     * Tokens that are never frozen onto a certificate, whatever a resolver
     * answers.
     *
     * The frozen bag is the union of the registry rather than what a template
     * names, so without this the learner's plaintext address landed in
     * context_snapshot and stayed there for the retention life of the
     * credential, copied verbatim into every reissue. The design's user token
     * family for a credential is first name, last name, full name and masked
     * address; the unmasked address belongs to the mail surface, where the
     * recipient is the addressee.
     *
     * @var list<string>
     */
    public const NEVER_FROZEN = ['user.email'];

    public function __construct(
        private readonly TokenResolverRegistry $resolvers,
    ) {
    }

    /**
     * The floor above, plus whatever every registered family declares about
     * its own tokens.
     *
     * A closed core constant meant a package registering a resolver that can
     * answer a plaintext identifier had no way to keep it out of the frozen
     * snapshot except by editing core, which inverts the extensibility rule
     * exactly where the privacy table is strictest. The core list stays as a
     * floor, so a family cannot un-declare what the product already refuses.
     *
     * @return list<string>
     */
    public function neverFrozen(): array
    {
        $tokens = self::NEVER_FROZEN;

        foreach ($this->resolvers->all() as $resolver) {
            if (!$resolver instanceof DeclaresUnfrozenTokens) {
                continue;
            }

            foreach ($resolver->neverFrozen() as $token) {
                if (!\in_array($token, $tokens, true)) {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, mixed> $parameters what the surface assembled: the row, the names, the credit
     *
     * @return array<string, string>
     */
    public function resolve(
        array $parameters,
        ?int $userId,
        ?int $courseId,
        string $locale,
        string $timezone = 'UTC',
    ): array {
        $context = TokenContext::create($parameters, $locale, $userId, $courseId, $timezone);
        $unfrozen = $this->neverFrozen();
        $values = [];

        foreach ($this->resolvers->all() as $resolver) {
            foreach ($resolver->tokens() as $token) {
                if (\in_array($token, $unfrozen, true)) {
                    continue;
                }

                $value = $resolver->resolve($token, $context);

                if ($value !== null && $value !== '') {
                    $values[$token] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * The whole bag for one issuance: the facts, then every resolver's own
     * contribution.
     *
     * @return array<string, string>
     */
    public function resolveForIssuance(IssuanceParameterContext $context): array
    {
        return $this->resolve(
            $this->parametersFor($context),
            $context->userId,
            $context->courseId,
            $context->locale,
            $context->timezone,
        );
    }

    /**
     * The parameter bag one issuance hands the resolvers.
     *
     * The facts of the issuance are here because they belong to no family: a
     * code, a uuid and three dates are the certificate's own. Everything a
     * family derives is contributed by that family, so the credit rows, the
     * rubric bands, the competency list and the run a learner sat in each
     * arrive from the resolver that reads them back.
     *
     * A contributor never overwrites a key already present, so the facts win
     * over a contribution and the first registration wins over a later one.
     *
     * @return array<string, mixed>
     */
    public function parametersFor(IssuanceParameterContext $context): array
    {
        $parameters = array_filter([
            'code' => $context->code,
            'verification_code' => $context->code,
            'uuid' => $context->uuid,
            'issued_at' => LocalisedDate::long($context->issuedAt, $context->locale, $context->timezone),
            'issued_at_iso' => LocalisedDate::iso($context->issuedAt),
            'completed_at' => LocalisedDate::long(
                $context->completedAt ?? $context->issuedAt,
                $context->locale,
                $context->timezone,
            ),
            'expires_at' => $context->expiresAt === null
                ? null
                : LocalisedDate::long($context->expiresAt, $context->locale, $context->timezone),
            'learner_name' => $context->learnerName,
            'course_id' => $context->courseId,
            'course_title' => $context->courseTitle,
        ], static fn (mixed $value): bool => $value !== null);

        foreach ($this->resolvers->all() as $resolver) {
            if (!$resolver instanceof ContributesIssuanceParameters) {
                continue;
            }

            foreach ($resolver->parametersFor($context) as $key => $value) {
                if ($value !== null && !\array_key_exists($key, $parameters)) {
                    $parameters[$key] = $value;
                }
            }
        }

        return $parameters;
    }

    /**
     * Every placeholder this build can answer, which the designer's
     * autocomplete and the save-time validation both read. It is the
     * registry's own answer rather than a list, so a package that adds a
     * resolver appears in both without a core edit.
     *
     * @return list<string>
     */
    public function availableTokens(): array
    {
        $unfrozen = $this->neverFrozen();
        $tokens = [];

        foreach ($this->resolvers->all() as $resolver) {
            foreach ($resolver->tokens() as $token) {
                if (!\in_array($token, $unfrozen, true)) {
                    $tokens[] = $token;
                }
            }
        }

        sort($tokens);

        return array_values(array_unique($tokens));
    }

    /**
     * The token families, for the palette. Order is the registry's, which is
     * registration order, and the surface that shows these to a person
     * declares its own.
     *
     * @return list<array{key: string, prefix: string, label: string, tokens: list<string>}>
     */
    public function families(): array
    {
        $unfrozen = $this->neverFrozen();

        return array_map(
            static fn (TokenResolverInterface $resolver): array => [
                'key' => $resolver->key(),
                'prefix' => $resolver->prefix(),
                'label' => $resolver->label(),
                'tokens' => array_values(array_filter(
                    $resolver->tokens(),
                    static fn (string $token): bool => !\in_array($token, $unfrozen, true),
                )),
            ],
            $this->resolvers->all(),
        );
    }
}
