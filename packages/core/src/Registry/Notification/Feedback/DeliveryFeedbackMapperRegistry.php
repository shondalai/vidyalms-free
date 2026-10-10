<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Feedback;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\FeedbackContext;

/**
 * @extends AbstractProviderRegistry<DeliveryFeedbackMapperInterface>
 */
final class DeliveryFeedbackMapperRegistry extends AbstractProviderRegistry
{
    public const EXTENSION_POINT = 'delivery_feedback_mapper';

    protected function providerInterface(): string
    {
        return DeliveryFeedbackMapperInterface::class;
    }

    /**
     * The first registered mapper that claims this payload, or null. A null
     * is not an error: the route answers 202 with mapped false, logs the body
     * unparsed and writes no suppression, which is this registry's published
     * miss.
     */
    public function claimant(string $body, string $contentType, FeedbackContext $context): ?DeliveryFeedbackMapperInterface
    {
        foreach ($this->all() as $mapper) {
            if ($mapper->claims($body, $contentType, $context)) {
                return $mapper;
            }
        }

        return null;
    }
}
