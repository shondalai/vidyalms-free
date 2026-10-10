<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/** The only editable notification-template document. It contains no HTML. */
final class TemplateSource
{
    public const FIELDS = [
        'subject',
        'preheader',
        'heading',
        'paragraphs',
        'action_label',
        'action_url',
        'footer',
        'logo_url',
        'logo_alt',
        'accent_color',
        'short_body',
    ];

    /** @param list<string> $paragraphs */
    private function __construct(
        public readonly string $subject,
        public readonly string $preheader,
        public readonly string $heading,
        public readonly array $paragraphs,
        public readonly ?string $actionLabel,
        public readonly ?string $actionUrl,
        public readonly string $footer,
        public readonly ?string $logoUrl,
        public readonly ?string $logoAlt,
        public readonly string $accentColor,
        public readonly ?string $shortBody,
    ) {
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        $errors = [];

        foreach (array_diff(array_keys($source), self::FIELDS) as $unknown) {
            $errors['source.' . $unknown] = 'This field is not part of a notification template.';
        }

        $subject = self::requiredString($source, 'subject', $errors, 255);
        $preheader = self::requiredString($source, 'preheader', $errors, 255);
        $heading = self::requiredString($source, 'heading', $errors, 255);
        $footer = self::requiredString($source, 'footer', $errors, 1000);
        $actionLabel = self::optionalString($source, 'action_label', $errors, 120);
        $actionUrl = self::optionalString($source, 'action_url', $errors, 2048);
        $logoUrl = self::optionalString($source, 'logo_url', $errors, 2048);
        $logoAlt = self::optionalString($source, 'logo_alt', $errors, 255);
        $shortBody = self::optionalString($source, 'short_body', $errors, 500);

        $paragraphs = [];
        $rawParagraphs = $source['paragraphs'] ?? null;

        if (!\is_array($rawParagraphs) || $rawParagraphs === []) {
            $errors['paragraphs'] = 'At least one paragraph is required.';
        } elseif (!array_is_list($rawParagraphs)) {
            $errors['paragraphs'] = 'Paragraphs must be an ordered list.';
        } elseif (\count($rawParagraphs) > 20) {
            $errors['paragraphs'] = 'A template cannot contain more than 20 paragraphs.';
        } else {
            foreach ($rawParagraphs as $index => $paragraph) {
                if (!\is_string($paragraph) || trim($paragraph) === '') {
                    $errors['paragraphs.' . $index] = 'A paragraph cannot be empty.';
                    continue;
                }

                if (mb_strlen($paragraph) > 5000) {
                    $errors['paragraphs.' . $index] = 'A paragraph cannot exceed 5000 characters.';
                    continue;
                }

                $paragraphs[] = trim($paragraph);
            }
        }

        $accentColor = $source['accent_color'] ?? null;

        if (!\is_string($accentColor) || preg_match('/^#[0-9A-Fa-f]{6}$/', $accentColor) !== 1) {
            $errors['accent_color'] = 'Use a six-digit hexadecimal colour such as #3155A6.';
            $accentColor = '#3155A6';
        } else {
            $accentColor = strtoupper($accentColor);
        }

        if (($actionLabel === null) !== ($actionUrl === null)) {
            $errors[$actionLabel === null ? 'action_label' : 'action_url'] =
                'The action label and URL must be supplied together.';
        }

        if (($logoUrl === null) !== ($logoAlt === null)) {
            $errors[$logoUrl === null ? 'logo_url' : 'logo_alt'] =
                'The logo URL and alternative text must be supplied together.';
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException($errors);
        }

        return new self(
            $subject,
            $preheader,
            $heading,
            $paragraphs,
            $actionLabel,
            $actionUrl,
            $footer,
            $logoUrl,
            $logoAlt,
            $accentColor,
            $shortBody,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'preheader' => $this->preheader,
            'heading' => $this->heading,
            'paragraphs' => $this->paragraphs,
            'action_label' => $this->actionLabel,
            'action_url' => $this->actionUrl,
            'footer' => $this->footer,
            'logo_url' => $this->logoUrl,
            'logo_alt' => $this->logoAlt,
            'accent_color' => $this->accentColor,
            'short_body' => $this->shortBody,
        ];
    }

    /** @return array<string, string> scalar authoring fields, with paragraph paths expanded */
    public function textFields(): array
    {
        $fields = [
            'subject' => $this->subject,
            'preheader' => $this->preheader,
            'heading' => $this->heading,
            'footer' => $this->footer,
        ];

        foreach ($this->paragraphs as $index => $paragraph) {
            $fields['paragraphs.' . $index] = $paragraph;
        }

        foreach (
            [
                'action_label' => $this->actionLabel,
                'action_url' => $this->actionUrl,
                'logo_url' => $this->logoUrl,
                'logo_alt' => $this->logoAlt,
                'short_body' => $this->shortBody,
            ] as $field => $value
        ) {
            if ($value !== null) {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed>  $source
     * @param array<string, string> $errors
     */
    private static function requiredString(array $source, string $field, array &$errors, int $max): string
    {
        $value = self::optionalString($source, $field, $errors, $max);

        if ($value === null) {
            $errors[$field] = 'This field is required.';

            return '';
        }

        return $value;
    }

    /**
     * @param array<string, mixed>  $source
     * @param array<string, string> $errors
     */
    private static function optionalString(array $source, string $field, array &$errors, int $max): ?string
    {
        $value = $source[$field] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!\is_string($value)) {
            $errors[$field] = 'A text value is required.';

            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            $errors[$field] = sprintf('This field cannot exceed %d characters.', $max);

            return null;
        }

        return $value;
    }
}
