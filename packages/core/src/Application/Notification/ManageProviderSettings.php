<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlProviderSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretRef;
use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProviderEdition;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\ProviderPackages;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\Settings\DeclaresSettings;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsDeclarationContext;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsFields;

/**
 * Reading and writing one provider's configuration, at any scope, for any
 * registry.
 *
 * One table and one pair of routes so that configuring a provider is a row
 * rather than a migration: a policy needing a dedicated column would make the
 * ninth policy a schema change, which is the failure the registry exists to
 * prevent, and it privileges whichever providers shipped first.
 *
 * A secret is write-only. It goes in and never comes back, because a settings
 * screen that echoed a shared secret would put it in every browser cache that
 * ever opened the page.
 */
final class ManageProviderSettings
{
    /** Fields whose value is never returned, matched on the key's suffix. */
    private const SECRET_SUFFIXES = ['secret', 'password', 'token', 'key'];

    public function __construct(
        private readonly Registries $registries,
        private readonly SqlProviderSettingsRepository $settings,
        private readonly AccessGuard $guard,
        private readonly TranslatorPort $translator,
        private readonly ?ProviderPackages $packages = null,
    ) {
    }

    /**
     * The stored registry keys whose spelling predates this derivation.
     *
     * `registry_key` is a stored column, so these two may never change: rows
     * written under them exist on every site that has configured an audience
     * or an email channel. Everything else derives from the property name.
     */
    private const LEGACY_KEYS = [
        'audiences' => 'Audience',
        'notificationChannels' => 'Notification',
    ];

    /**
     * Every seated registry, keyed by the name its `provider_settings` rows
     * are stored under.
     *
     * Derived from registered provider collections and their storage keys.
     *
     * @return array<string, AbstractProviderRegistry<covariant \Shondalai\VidyaLMS\Core\Registry\ProviderInterface>>
     */
    private function registryMap(): array
    {
        $map = [];

        foreach (get_object_vars($this->registries) as $property => $registry) {
            if (!$registry instanceof AbstractProviderRegistry) {
                continue;
            }

            $map[self::canonicalKeyOf($property, $registry)] = $registry;
        }

        return $map;
    }

    /**
     * The key one registry's rows are stored under.
     *
     * Use the registry's declared storage key when available.
     *
     * @param AbstractProviderRegistry<covariant \Shondalai\VidyaLMS\Core\Registry\ProviderInterface> $registry
     */
    private static function canonicalKeyOf(string $property, AbstractProviderRegistry $registry): string
    {
        if (isset(self::LEGACY_KEYS[$property])) {
            return self::LEGACY_KEYS[$property];
        }

        if (\defined($registry::class . '::EXTENSION_POINT')) {
            $declared = \constant($registry::class . '::EXTENSION_POINT');

            if (\is_string($declared) && $declared !== '') {
                return $declared;
            }
        }

        return self::registryKeyOf($property);
    }

    /**
     * `availabilityConditions` to `AvailabilityCondition`.
     *
     * Singular because the key names one extension point and not the
     * collection of them, which is the convention every stored row already
     * follows. The three plural endings are the ones the seated properties
     * actually use; a property whose plural this cannot undo keeps its own
     * spelling, which is a stable key either way.
     *
     * It is now the fallback for a registry that publishes no
     * `EXTENSION_POINT`, and it is also the **alias** every caller may still
     * use, answered from the canonical stored row.
     */
    private static function registryKeyOf(string $property): string
    {
        $singular = match (true) {
            str_ends_with($property, 'ies') => substr($property, 0, -3) . 'y',
            str_ends_with($property, 'ses') => substr($property, 0, -2),
            str_ends_with($property, 's') => substr($property, 0, -1),
            default => $property,
        };

        return ucfirst($singular);
    }

    /**
     * The canonical key for whatever spelling a caller asked with, or null.
     *
     * Accepting the derived alias is what lets an existing screen keep its
     * literal while the row moves to the published key; answering the
     * canonical one is what stops the two drifting again.
     */
    private function canonicalKey(string $requested): ?string
    {
        $map = $this->registryMap();

        if (isset($map[$requested])) {
            return $requested;
        }

        foreach (get_object_vars($this->registries) as $property => $registry) {
            if (!$registry instanceof AbstractProviderRegistry) {
                continue;
            }

            if (self::registryKeyOf($property) === $requested || $property === $requested) {
                return self::canonicalKeyOf($property, $registry);
            }
        }

        return null;
    }

    /**
     * The spelling a row may already be stored under, where it differs.
     *
     * A site that configured a provider before the canonical key existed has
     * its row under the derived alias. Rather than a schema migration for one
     * column, the alias is read through and the next save moves the row, so no
     * configuration is stranded and no site has to run anything.
     */
    private function aliasKey(string $canonical): ?string
    {
        foreach (get_object_vars($this->registries) as $property => $registry) {
            if (!$registry instanceof AbstractProviderRegistry) {
                continue;
            }

            if (self::canonicalKeyOf($property, $registry) !== $canonical) {
                continue;
            }

            $alias = self::registryKeyOf($property);

            return $alias === $canonical ? null : $alias;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function list(Principal $principal, string $registryKey, string $scopeType, int $scopeId): array
    {
        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);

        // The spelling the caller used is echoed back, so an existing screen
        // sees the key it asked with; the row is read under the canonical one.
        $requested = $registryKey;
        $registryKey = $this->canonicalKey($registryKey) ?? $registryKey;
        $registry = $this->registryMap()[$registryKey] ?? null;

        if ($registry === null) {
            throw EntityNotFoundException::for('Registry', 0);
        }

        $this->requireScope($scopeType);
        $alias = $this->aliasKey($registryKey);
        $providers = [];

        foreach ($registry->all() as $provider) {
            $record = $this->settings->record($registryKey, $provider->key(), $scopeType, $scopeId);

            if ($record === null && $alias !== null) {
                $record = $this->settings->record($alias, $provider->key(), $scopeType, $scopeId);
            }

            $stored = $record === null ? [] : $record->params;
            $label = $this->copy(method_exists($provider, 'label') ? (string) $provider->label() : $provider->key())
                ?? $provider->key();

            $providers[] = [
                'key' => $provider->key(),
                'label' => $label,
                'description' => null,
                'group' => $requested,
                'order' => 0,
                'edition' => ProviderEdition::of($this->packages, $registryKey, $provider->key()),
                'available' => true,
                'package' => $this->packages?->packageOf($registryKey, $provider->key()),
                // The two R2 columns, reported as facts and never as values.
                // A reference is write-only for the same reason a secret
                // field is, so what a screen is told is whether one is
                // stored; an absent row is enabled, which is what every
                // registry's free default already assumes.
                'enabled' => $record === null || $record->enabled,
                'secret_ref_set' => $record !== null && $record->secretRef !== null,
                'groups' => $this->groupsFor(
                    $provider,
                    $label,
                    $stored,
                    SettingsDeclarationContext::create($registryKey, $scopeType, $scopeId),
                ),
            ];
        }

        return [
            'registry_key' => $requested,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'providers' => $providers,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param ?string              $secretRef a pointer resolved through SecretsPort (`env:VIDYALMS_SECRET_NAME`,
     *                                        `store:KEY`), never a value. Null leaves whatever is
     *                                        stored alone, which is the same rule the write-only
     *                                        parameter fields follow.
     * @param ?bool                $enabled   null leaves the stored state alone
     *
     * @return array<string, mixed>
     */
    public function save(
        Principal $principal,
        string $registryKey,
        string $providerKey,
        string $scopeType,
        int $scopeId,
        array $params,
        ?string $secretRef = null,
        ?bool $enabled = null,
    ): array {
        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);

        $registryKey = $this->canonicalKey($registryKey) ?? $registryKey;
        $registry = $this->registryMap()[$registryKey] ?? null;

        if ($registry === null || !$registry->has($providerKey)) {
            throw EntityNotFoundException::for('Provider', 0);
        }

        $this->requireScope($scopeType);

        $alias = $this->aliasKey($registryKey);
        $provider = $registry->get($providerKey);
        if ($provider instanceof \Shondalai\VidyaLMS\Core\Registry\Settings\AuthorizesSettingsWrite) {
            $provider->requireSettingsWrite($principal);
        }
        $existing = $this->settings->find($registryKey, $providerKey, $scopeType, $scopeId);

        if ($existing === null && $alias !== null) {
            // The row a site wrote before the canonical key existed. It is
            // carried forward here rather than by a schema migration for one
            // column, so nothing is stranded and nothing has to be run.
            $existing = $this->settings->find($alias, $providerKey, $scopeType, $scopeId);
        }

        $existing ??= [];

        // A secret never enters the params bag, on any provider, ever.
        //
        // `write_only` masked the read path and stored the value in cleartext
        // regardless, so the email channel's feedback secret sat in
        // provider_settings.params on every install while the secret_ref
        // column two fields away refused the same value by name. A refusal
        // here is what makes the two columns answer one question one way, and
        // it names the column that takes the value so the person typing knows
        // where to put it instead.
        foreach (array_keys($params) as $key) {
            if ($this->isSecretReference((string) $key)) {
                if ($params[$key] !== null && !\is_string($params[$key])) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    throw ValidationException::forField((string) $key, 'Enter a secret reference, not a credential.');
                }
                $params[$key] = $this->secretRef($params[$key]);
            }
            if ($this->isSecret((string) $key)) {
                throw ValidationException::forField(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    (string) $key,
                    'A secret is never stored in a provider setting. Store the value in the host\'s secret '
                    . 'store and point this provider\'s "secret_ref" at it as "env:VIDYALMS_SECRET_NAME" or "store:KEY".',
                );
            }
        }

        // A stored secret from before that rule is carried forward untouched
        // rather than rewritten or silently dropped: rewriting it would put
        // the cleartext back under a new updated_at, and dropping it would
        // close a feedback route a site is relying on with no warning. The
        // uninstall and the erasure paths are what remove it.
        foreach ($existing as $key => $value) {
            if ($this->isSecret((string) $key) && !\array_key_exists($key, $params)) {
                $params[$key] = $value;
            }
        }

        $this->settings->save(
            $registryKey,
            $providerKey,
            $scopeType,
            $scopeId,
            $params,
            $principal->userId,
            $this->secretRef($secretRef),
            $enabled,
        );

        return $this->list($principal, $registryKey, $scopeType, $scopeId);
    }

    /**
     * A secret reference, checked against the port's own closed grammar.
     *
     * **The two columns this checks for had a reader and no writer.**
     * `ProviderSecrets` resolves `provider_settings.secret_ref` and
     * `SqlProviderSettingsRepository::save()` accepts one, and between the two
     * there was no route, no use case and no screen that could put a value
     * there: a gateway package would have had to write the column with SQL of
     * its own and parse the grammar itself, which is the per-package
     * invention `SecretsPort` exists to prevent, for the one value whose leak
     * is unrecoverable. `enabled` was in the same position, so a provider
     * switch disabled nothing.
     *
     * The refusal names the grammar rather than saying invalid, because the
     * person typing has the secret on their clipboard and needs to be told
     * what to do with it instead. Accepting an unprefixed string would store
     * the secret itself in a table that is readable on every shared-hosting
     * install, and it would appear to work.
     */
    private function secretRef(?string $ref): ?string
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        if (SecretRef::parse($ref) === null) {
            throw ValidationException::forField(
                'secret_ref',
                'A secret reference points at the host\'s secret store and is never the secret itself. '
                . 'Store the value as an environment variable and reference it as "env:VIDYALMS_SECRET_NAME", or in the '
                . 'host\'s own secret store as "store:KEY".',
            );
        }

        if (\strlen($ref) > 190) {
            throw ValidationException::forField('secret_ref', 'A secret reference is at most 190 characters.');
        }

        return $ref;
    }

    private function requireScope(string $scopeType): void
    {
        if (!\in_array($scopeType, SqlProviderSettingsRepository::scopeOrder(), true)) {
            throw ValidationException::forField('scope_type', 'Unknown scope.');
        }
    }

    /**
     * The provider's own declaration, with what this scope has stored merged
     * onto it.
     *
     * Declared first and stored second, because the screen has to be able to
     * show a field nobody has written yet: that is the whole defect. The
     * email channel's feedback secret was accepted by the PUT and had nowhere
     * to be typed, because the field list was derived from the stored row and
     * the stored row is empty until the field that is not there is written.
     *
     * A stored key the declaration does not name is still reported, so a
     * value written by an older version of a provider, or by a package that
     * has since been removed, is visible rather than silently orphaned.
     *
     * @param array<string, mixed> $stored
     *
     * @return list<array<string, mixed>>
     */
    private function groupsFor(
        ProviderInterface $provider,
        string $label,
        array $stored,
        SettingsDeclarationContext $context,
    ): array {
        $declared = null;

        if ($provider instanceof DeclaresSettings) {
            try {
                $declared = $provider->settingsGroup($context);
            } catch (\Throwable) {
                // A third-party provider that cannot describe itself costs
                // its own fields and not the whole screen, which is the same
                // direction the periodic enqueue takes with a sweep that
                // cannot answer.
                $declared = null;
            }
        }

        $fields = [];
        $named = [];

        foreach ($declared['fields'] ?? [] as $field) {
            if (!\is_array($field) || !\is_string($field['key'] ?? null)) {
                continue;
            }

            $key = $field['key'];
            $named[$key] = true;
            $secret = ($field['write_only'] ?? false) === true || $this->isSecret($key);

            $field['write_only'] = $secret;
            $field['current'] = $secret
                ? null
                : $this->visibleValue($key, $stored[$key] ?? null);
            $field['is_set'] = \array_key_exists($key, $stored);
            $field['label'] = $this->copy($field['label'] ?? $key);
            $field['description'] = $this->copy($field['description'] ?? null);
            $fields[] = $field;
        }

        foreach ($this->fieldsOf($stored) as $field) {
            if (!isset($named[(string) $field['key']])) {
                $fields[] = $field;
            }
        }

        if ($fields === []) {
            return [];
        }

        return [[
            'key' => \is_string($declared['key'] ?? null) ? $declared['key'] : $provider->key(),
            'label' => \is_string($declared['label'] ?? null) && $declared['label'] !== ''
                ? (string) $this->copy($declared['label'])
                : $label,
            'description' => $this->copy($declared['description'] ?? null),
            'fields' => $fields,
        ]];
    }

    /**
     * A declared string, translated when it is a catalogue key.
     *
     * Both conventions are in the shipped code and neither is wrong: a
     * provider holding a translator translates its own copy, which is the
     * rule the quiz settings groups follow, and a provider constructed with
     * only a repository (every notification policy) has nothing to translate
     * with and returns the key, which is what its own label() already does.
     * Translating only what is recognisably a key serves both without
     * translating an English sentence twice.
     */
    private function copy(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return str_starts_with($value, 'vidyalms.') ? $this->translator->translate($value) : $value;
    }

    /**
     * A stored value nothing declared, typed by what it holds.
     *
     * The types are the contract's SettingsField enumeration and not their
     * own vocabulary: this route was answering "text" and "number", which the
     * form renders correctly by luck rather than by agreement, and "number"
     * for an integer loses the step the field should carry.
     *
     * @param array<string, mixed> $stored
     *
     * @return list<array<string, mixed>>
     */
    private function fieldsOf(array $stored): array
    {
        $fields = [];

        foreach ($stored as $key => $value) {
            $secret = $this->isSecret((string) $key);

            $fields[] = [
                'key' => (string) $key,
                'type' => $this->typeOf($value),
                'label' => (string) $key,
                'description' => null,
                'default' => null,
                'current' => $secret ? null : $this->visibleValue((string) $key, $value),
                'write_only' => $secret,
                'required' => false,
                'is_set' => true,
            ];
        }

        return $fields;
    }

    private function typeOf(mixed $value): string
    {
        if (\is_bool($value)) {
            return SettingsFields::TYPE_BOOLEAN;
        }

        if (\is_int($value)) {
            return SettingsFields::TYPE_INTEGER;
        }

        if (\is_float($value)) {
            return SettingsFields::TYPE_NUMBER;
        }

        return \is_array($value) ? SettingsFields::TYPE_OBJECT : SettingsFields::TYPE_STRING;
    }

    private function isSecretReference(string $key): bool
    {
        return $key === 'secret_ref' || $key === 'key_ref'
            || str_ends_with($key, '_secret_ref') || str_ends_with($key, '_key_ref');
    }

    private function visibleValue(string $key, mixed $value): mixed
    {
        // Older rows may contain accidentally pasted key material. Never echo it.
        if ($this->isSecretReference($key) && (!is_string($value) || SecretRef::parse($value) === null)) {
            return null;
        }
        return $value;
    }

    private function isSecret(string $key): bool
    {
        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with(strtolower($key), $suffix)) {
                return true;
            }
        }

        return false;
    }
}
