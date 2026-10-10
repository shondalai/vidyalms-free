<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;

/** Converts provider declarations into the published SettingsGroup and TokenSource wire shapes. */
final class CertificatePalettePresenter
{
    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    /** @param array<string, mixed> $schema
     *  @param array<string, mixed> $defaults
     *  @return list<array<string, mixed>>
     */
    public function groups(string $key, string $label, array $schema, array $defaults): array
    {
        $fields = $schema['fields'] ?? [];

        if (!is_array($fields) || $fields === []) {
            return [];
        }

        return [['key' => $key, 'label' => $this->translator->translate($label), 'fields' => $this->fields($fields, $defaults)]];
    }

    /** @param array<array-key, mixed> $declarations
     *  @param array<array-key, mixed> $defaults
     *  @return list<array<string, mixed>>
     */
    private function fields(array $declarations, array $defaults): array
    {
        $fields = [];

        foreach ($declarations as $key => $declaration) {
            if (!is_array($declaration)) {
                continue;
            }

            $key = is_string($key) ? $key : ($declaration['key'] ?? null);

            if (!is_string($key) || $key === '') {
                continue;
            }

            $label = $declaration['label'] ?? 'vidyalms.certificate_element.field.' . $key;
            $type = $declaration['type'] ?? 'string';
            $default = $defaults[$key] ?? $declaration['default'] ?? null;
            $field = [
                'key' => $key,
                'label' => $this->translator->translate(is_string($label) ? $label : $key),
                'type' => $type,
                'default' => $default,
            ];

            foreach (['minimum', 'maximum', 'required', 'nullable', 'multiline', 'source', 'available'] as $property) {
                if (array_key_exists($property, $declaration)) {
                    $field[$property] = $declaration[$property];
                }
            }

            foreach (['description', 'unavailable_reason'] as $property) {
                if (is_string($declaration[$property] ?? null)) {
                    $field[$property] = $this->translator->translate($declaration[$property]);
                }
            }

            if (is_array($declaration['enum'] ?? null)) {
                $field['type'] = 'enum';
                $field['options'] = [];

                foreach ($declaration['enum'] as $option) {
                    if (is_string($option)) {
                        $field['options'][] = ['key' => $option, 'label' => $this->translator->translate($option)];
                    }
                }
            } elseif ($type === 'enum' && is_array($declaration['options'] ?? null)) {
                $field['options'] = $declaration['options'];
            }

            if ($type === 'object') {
                $children = $declaration['properties'] ?? $declaration['fields'] ?? [];
                $field['fields'] = $this->fields(is_array($children) ? $children : [], is_array($default) ? $default : []);
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /** @param list<array{key: string, prefix: string, label: string, tokens: list<string>}> $families
     *  @return list<array<string, mixed>>
     */
    public function tokens(array $families): array
    {
        $sources = [];

        foreach ($families as $family) {
            $fields = [];
            $prefix = $family['prefix'] . '.';

            foreach ($family['tokens'] as $token) {
                if (str_starts_with($token, $prefix) && strlen($token) > strlen($prefix)) {
                    $fields[] = ['key' => substr($token, strlen($prefix)), 'label' => $token];
                }
            }

            $sources[] = [
                'key' => $family['prefix'],
                'label' => $this->translator->translate($family['label']),
                // Only installed resolvers reach this catalogue; value availability is decided at render time.
                'available' => true,
                'fields' => $fields,
            ];
        }

        return $sources;
    }
}
