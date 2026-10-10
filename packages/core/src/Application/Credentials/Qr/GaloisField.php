<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials\Qr;

/**
 * GF(256) arithmetic under the QR primitive polynomial x^8 + x^4 + x^3 + x^2 + 1
 * (0x11D), which is the field the Reed-Solomon error correction below is
 * defined over.
 *
 * It is here rather than pulled from a package because the coding standards
 * allow only PSR interfaces as external code under src/, and a certificate
 * whose verification link cannot be scanned weakens the one feature this
 * product sells as its differentiator.
 *
 * The two tables are read through accessors rather than directly, and the
 * exponent accessor reduces its power modulo 255 rather than holding a
 * doubled table. Both are the same arithmetic; this form is the one whose
 * indices are provably inside the table.
 */
final class GaloisField
{
    private const PRIMITIVE = 0x11D;
    private const ORDER = 255;

    /** @var array<int, int> */
    private array $exp;

    /** @var array<int, int> */
    private array $log;

    public function __construct()
    {
        $exp = [];
        $log = [];
        $value = 1;

        for ($i = 0; $i < self::ORDER; $i++) {
            $exp[$i] = $value;
            $log[$value] = $i;
            $value <<= 1;

            if ($value >= 256) {
                $value ^= self::PRIMITIVE;
            }
        }

        $this->exp = $exp;
        $this->log = $log;
    }

    public function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return $this->exponent($this->logarithm($a) + $this->logarithm($b));
    }

    /**
     * The generator polynomial for the given number of error correction
     * codewords, as coefficients from the highest degree down.
     *
     * @return list<int>
     */
    public function generator(int $degree): array
    {
        $polynomial = [1];

        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, \count($polynomial) + 1, 0);

            foreach ($polynomial as $index => $coefficient) {
                $next[$index] ^= $coefficient;
                $next[$index + 1] ^= $this->multiply($coefficient, $this->exponent($i));
            }

            $polynomial = array_values($next);
        }

        return $polynomial;
    }

    /**
     * The error correction codewords for one block.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    public function remainder(array $data, int $degree): array
    {
        $generator = $this->generator($degree);
        $remainder = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ ($remainder[0] ?? 0);
            array_shift($remainder);
            $remainder[] = 0;

            foreach ($generator as $index => $coefficient) {
                if ($index === 0) {
                    continue;
                }

                $remainder[$index - 1] ^= $this->multiply($coefficient, $factor);
            }
        }

        return array_values($remainder);
    }

    /**
     * The antilog. Powers repeat every 255, which is what the modulo
     * expresses and what a doubled table would express by copying.
     */
    private function exponent(int $power): int
    {
        return $this->exp[$power % self::ORDER] ?? 1;
    }

    private function logarithm(int $value): int
    {
        return $this->log[$value] ?? 0;
    }
}
