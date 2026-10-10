<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification;

/**
 * One `provider_settings` row, whole.
 *
 * It exists because the repository used to answer with the parameter bag
 * alone, so two of the table's columns had no reader anywhere in the product:
 * `secret_ref`, whose stated purpose is that a leaked signing secret lets an
 * attacker forge entitlement grants, and `enabled`, which disabled nothing. A
 * gateway package would have had to write both with SQL of its own and parse
 * the reference grammar itself, which is the per-adapter invention the
 * SecretsPort exists to prevent.
 *
 * The parameter bag stays a plain array rather than becoming a typed object,
 * because only the owning provider interprets it and the version beside it is
 * how a provider upgrades its own shape.
 */
final class ProviderSettingsRecord
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public readonly array $params,
        public readonly int $paramsVersion,
        /** A pointer resolved through SecretsPort (`env:NAME`, `store:KEY`), never a value. */
        public readonly ?string $secretRef,
        public readonly bool $enabled,
    ) {
    }
}
