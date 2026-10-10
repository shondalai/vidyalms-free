<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;

final class FakeRandom implements RandomPort
{
    private int $uuidCounter = 0;

    private int $codeCounter = 0;

    public function uuid(): string
    {
        $this->uuidCounter++;

        return sprintf('00000000-0000-4000-8000-%012d', $this->uuidCounter);
    }

    public function verificationCode(int $length): string
    {
        $this->codeCounter++;

        return strtoupper(substr(str_pad('CODE' . $this->codeCounter, $length, 'X'), 0, $length));
    }
}
