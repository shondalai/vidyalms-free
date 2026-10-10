<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CreditSchemeIdentityRepository;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\Credit\CreditSchemeRegistry;

/**
 * GET /me/credit-identities
 * PUT /me/credit-identities
 *
 * The certifying board id or partial date of birth a credit scheme declared
 * it needs, supplied by the learner and stored outside the user model.
 *
 * It is the learner's own route and never a manager's, because these are
 * identifiers about a person that the person is best placed to get right, and
 * because a scheme adding a field must never mean a change to the host CMS's
 * user record. Only the fields a registered scheme actually declares are
 * accepted: an open key-value store on a learner would be a personal data
 * surface with no stated purpose.
 */
final class CreditIdentitiesHandler implements HandlerInterface
{
    private const ROUTE = 'me.credit_identities';

    public function __construct(
        private readonly CreditSchemeIdentityRepository $identities,
        private readonly CreditSchemeRegistry $schemes,
        private readonly IdempotentExecution $idempotent,
        private readonly bool $write = false,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        if ($request->principal->isGuest()) {
            throw ApiProblem::unauthorized();
        }

        $userId = $request->principal->userId;

        if (!$this->write) {
            return ApiResponse::ok(['items' => $this->present($userId)]);
        }

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $userId): ApiResponse {
            $items = $request->body['items'] ?? null;

            if (!\is_array($items)) {
                throw ApiProblem::badRequest('A credit identity save takes an "items" array.');
            }

            foreach ($items as $item) {
                if (!\is_array($item)) {
                    continue;
                }

                $scheme = $item['scheme'] ?? null;
                $field = $item['field'] ?? null;
                $value = $item['value'] ?? null;

                if (!\is_string($scheme) || !\is_string($field) || !\is_string($value)) {
                    continue;
                }

                if (!$this->schemes->has($scheme)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    throw ApiProblem::badRequest(sprintf('No credit scheme "%s" is installed.', $scheme));
                }

                if (!\in_array($field, $this->schemes->get($scheme)->requiredLearnerFields(), true)) {
                    throw ApiProblem::badRequest(sprintf(
                        'The "%s" scheme does not declare a "%s" field.',
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        $scheme,
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        $field,
                    ));
                }

                if ($value === '') {
                    $this->identities->delete($userId, $scheme, $field);
                    continue;
                }

                $this->identities->save($userId, $scheme, $field, $value);
            }

            return ApiResponse::ok(['items' => $this->present($userId)]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function present(int $userId): array
    {
        $items = [];

        foreach ($this->schemes->all() as $scheme) {
            $required = $scheme->requiredLearnerFields();

            if ($required === []) {
                continue;
            }

            $held = $this->identities->forUser($userId, $scheme->key());

            $items[] = [
                'scheme' => $scheme->key(),
                'label' => $scheme->label(),
                'fields' => array_map(
                    static fn (string $field): array => [
                        'field' => $field,
                        'value' => $held[$field] ?? null,
                    ],
                    $required,
                ),
                'missing' => $this->identities->missingFor($userId, $scheme->key(), $required),
            ];
        }

        return $items;
    }
}
