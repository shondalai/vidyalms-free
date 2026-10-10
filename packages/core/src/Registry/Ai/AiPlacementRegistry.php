<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * The placement seam, with the other half of the no-mark redline.
 *
 * `AiTaskRegistry` refuses a task whose declared output schema binds a scored
 * or gated field, and `ReservedFields`' own docblock names the failure it is
 * for: "a vendor task declaring a suggested mark plus a vendor field-fill
 * placement". Only the task half was closed. A placement declaring
 * `fillableFields() = ['score', 'points_awarded', 'passed']` registered with
 * no refusal of any kind, and the generic mount then had the placement's word
 * for which fields it may bind. The standards page's sentence, "no placement
 * anywhere lists a scored or gated field", was true of the shipped nine and of
 * nothing else.
 *
 * So the same scan runs here, bound at boot the way the task registry's is,
 * and a placement listing a reserved field is refused at registration rather
 * than discovered on a gradebook screen.
 *
 * @extends AbstractProviderRegistry<AiPlacementInterface>
 */
final class AiPlacementRegistry extends AbstractProviderRegistry
{
    /**
     * @var callable(string): bool|null
     */
    private $reservedField = null;

    protected function providerInterface(): string
    {
        return AiPlacementInterface::class;
    }

    /**
     * Bind the scan that decides whether a field name is reserved.
     *
     * A callable rather than a class reference for the reason the task
     * registry gives: the reserved list is published by a free provider and a
     * registry in the seam namespace may not reach into the provider tree. An
     * unbound scan means the rule is not evaluated rather than silently
     * passing something nobody looked at.
     *
     * @param callable(string): bool $scan
     */
    public function refuseReservedFieldsWith(callable $scan): void
    {
        $this->reservedField = $scan;
    }

    protected function guardProvider(ProviderInterface $provider): void
    {
        if ($this->reservedField === null || !$provider instanceof AiPlacementInterface) {
            return;
        }

        $bound = [];

        foreach ($provider->fillableFields() as $field) {
            if (($this->reservedField)($field)) {
                $bound[] = $field;
            }
        }

        if ($bound === []) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'The placement "%s" lists the reserved fillable field or fields "%s". A score that plays a '
            . 'determining role in a downstream decision is automated decision-making even with a human '
            . 'button in front of it, and an availability, credential or enrolment field decides who may '
            . 'reach what. No placement anywhere lists one, and the rule is a contract rather than a '
            . 'screen note precisely so a field-filling helper cannot walk around it.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $provider->key(),
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            implode(', ', $bound),
        ));
    }
}
