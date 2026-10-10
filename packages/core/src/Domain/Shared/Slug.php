<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

/**
 * URL slugs for courses and lessons, stored in the core and mapped by each
 * adapter to its URL scheme.
 */
final class Slug
{
    private function __construct(
        public readonly string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if ($value === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) !== 1) {
            throw ValidationException::forField('slug', 'Slugs may contain lowercase letters, digits and single hyphens.');
        }

        if (\strlen($value) > 190) {
            throw ValidationException::forField('slug', 'Slugs may not exceed 190 characters.');
        }

        return new self($value);
    }

    /**
     * Derives a slug from a free-text title: lowercase, ASCII-folded where
     * possible, hyphen-separated.
     */
    public static function fromTitle(string $title): self
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $value = strtolower($ascii === false ? $title : $ascii);
        // Some iconv implementations transliterate accents to marks ("é" to "'e");
        // drop the marks so both behaviours produce the same slug.
        $value = str_replace(["'", '"', '`', '^', '~'], '', $value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');

        if ($value === '') {
            throw ValidationException::forField('slug', 'A slug could not be derived from the title.');
        }

        return self::fromString(substr($value, 0, 190));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
