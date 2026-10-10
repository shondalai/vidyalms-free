<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

/**
 * Where one element sits on its page, in millimetres from the page's top
 * left, after the reference point has been applied.
 *
 * Elements never compute this. mod_customcert made every element manage its
 * own position and alignment, found that it meant nineteen implementations of
 * the same arithmetic with nineteen chances to get it wrong, and put a layout
 * service in front of them. This is that service's answer, and it is the only
 * thing an element is told about where it is.
 */
final class ElementBox
{
    private function __construct(
        public readonly float $left,
        public readonly float $top,
        public readonly ?float $width,
        public readonly ?float $height,
        public readonly string $textAlign,
    ) {
    }

    public static function of(float $left, float $top, ?float $width, ?float $height, string $textAlign): self
    {
        return new self($left, $top, $width, $height, $textAlign);
    }

    /**
     * The absolute positioning declaration the HTML renderers use. Physical
     * units, because the page is paper: a page declared 297mm wide and an
     * element placed at 40mm mean the same thing in a browser print preview
     * and in a PDF engine, which pixels would not.
     */
    public function style(): string
    {
        $rules = [
            'position: absolute',
            'left: ' . self::mm($this->left),
            'top: ' . self::mm($this->top),
        ];

        if ($this->width !== null) {
            $rules[] = 'width: ' . self::mm($this->width);
        }

        if ($this->height !== null) {
            $rules[] = 'height: ' . self::mm($this->height);
        }

        $rules[] = 'text-align: ' . $this->textAlign;

        return implode('; ', $rules) . ';';
    }

    private static function mm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . 'mm';
    }
}
