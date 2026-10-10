<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Where a provisioning source resumes from, per organisation.
 *
 * The watermark is opaque and stays opaque. It is a modification timestamp for
 * one kind of source and a continuation link for another, and this product
 * deliberately does not know which it is holding: parsing it would make the
 * core's storage depend on a protocol's own paging model, which is exactly
 * what the mapper seam exists to keep out.
 *
 * It lives in provider_settings rather than in a table of its own, and the
 * reason is that it is the shape provider_settings was built for: a JSON body
 * only the owning provider interprets, under a version, keyed on
 * (registry, provider, scope type, scope id) with a unique index, with the
 * scope here being one organisation. A fifth identity table for one nullable
 * string per (source, organisation) pair would be a migration on both hosts
 * for a fact that already has a home.
 *
 * **The watermark is advanced only after the rows it covers are written.** A
 * run that fails part way leaves the previous value in place, because
 * re-reading a page is cheap and idempotent and skipping one is a person who
 * never arrives.
 */
final class ProvisioningWatermarks
{
    /** The registry these rows belong to, in provider_settings' own vocabulary. */
    public const REGISTRY = 'ProvisioningSource';

    /**
     * The scope word. It joins the five provider_settings already publishes,
     * and it is the first of them whose id is an organisation.
     */
    public const SCOPE = 'organisation';

    /** The shape of the stored body. */
    public const SHAPE_VERSION = 1;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * Where this source resumes from, or null for "everything".
     *
     * Null is what a first run and a reset both look like, and a source must
     * treat them the same: there is no third state, because a watermark this
     * product cannot read is a watermark it must not hand back.
     */
    public function watermarkFor(int $organisationId, string $sourceKey): ?string
    {
        $row = $this->database->selectOne($this->rowFor($organisationId, $sourceKey));

        if ($row === null) {
            return null;
        }

        $body = StoredShape::bodyOf($this->decode($row['params'] ?? null));
        $watermark = $body['watermark'] ?? null;

        return \is_string($watermark) && $watermark !== '' ? $watermark : null;
    }

    /**
     * Records where the next run resumes from.
     *
     * A null watermark is stored as a null and not as an absent row, because
     * "this source ran and has nothing to resume from" and "this source has
     * never run here" are two different facts and an operator reading the
     * screen needs to be able to tell them apart.
     */
    public function advance(int $organisationId, string $sourceKey, ?string $watermark): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $body = StoredShape::wrap([
            'watermark' => $watermark,
            'ran_at' => $now,
        ], self::SHAPE_VERSION);

        $this->database->upsert(
            'provider_settings',
            [
                'registry_key' => self::REGISTRY,
                'provider_key' => $sourceKey,
                'scope_type' => self::SCOPE,
                'scope_id' => $organisationId,
                'params' => json_encode($body, JSON_THROW_ON_ERROR),
                'params_version' => self::SHAPE_VERSION,
                'enabled' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['registry_key', 'provider_key', 'scope_type', 'scope_id'],
            ['params', 'params_version', 'updated_at'],
        );
    }

    /**
     * Clears a source's position so its next run reads everything.
     *
     * It exists because a customer whose directory was rebuilt has no other
     * way back: every row's modification stamp is newer than the watermark,
     * the source reads nothing, and the roster silently stops updating.
     */
    public function reset(int $organisationId, string $sourceKey): void
    {
        $this->advance($organisationId, $sourceKey, null);
    }

    private function rowFor(int $organisationId, string $sourceKey): Query
    {
        return Query::from('provider_settings')
            ->where('registry_key', '=', self::REGISTRY)
            ->where('provider_key', '=', $sourceKey)
            ->where('scope_type', '=', self::SCOPE)
            ->where('scope_id', '=', $organisationId);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }

        if (!\is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
