<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Domain\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Notification\EnvelopeSender;

/**
 * The reverse path, composed and taken apart.
 *
 * The composition rules are RFC 5321 section 4.5.3.1 and the recovery rule is
 * RFC 5233 subaddressing. Both halves are asserted together because a tag
 * that cannot be read back out is attribution nobody can use.
 */
final class EnvelopeSenderTest extends TestCase
{
    public function testDeliveryTagsAreNotPredictableOrReused(): void
    {
        $first = EnvelopeSender::tagFor(42);
        self::assertMatchesRegularExpression('/^vy42-[a-f0-9]{32}$/', $first);
        self::assertNotSame($first, EnvelopeSender::tagFor(42));
    }
    public function testTheAddressIsAFullVerpAddressAndNotABareTag(): void
    {
        $sender = EnvelopeSender::build('vy42', 'example.test');

        self::assertSame('bounce+vy42@example.test', $sender->address);
        self::assertNull($sender->reason);
        self::assertTrue($sender->isUsable());
    }

    /**
     * PHPMailer validates Sender with this exact function unless the host
     * replaces it, and refuses the whole message when it fails. A bare tag
     * fails it, which is what made every queued notification a permanent
     * failure.
     */
    public function testTheComposedAddressPassesThePhpMailerAddressValidator(): void
    {
        $address = EnvelopeSender::build('vy7', 'mail.example.test', 'verp')->address;

        self::assertIsString($address);
        self::assertNotFalse(filter_var($address, \FILTER_VALIDATE_EMAIL));
        self::assertFalse(filter_var('vy7', \FILTER_VALIDATE_EMAIL));
    }

    public function testThePrefixIsConfigurable(): void
    {
        self::assertSame(
            'return+vy9@example.test',
            EnvelopeSender::build('vy9', 'example.test', 'return')->address,
        );
    }

    /**
     * The field invites a site to paste the whole from address, and refusing
     * that would cost the site its attribution over a typo.
     */
    public function testAConfiguredAddressIsReadAsItsDomain(): void
    {
        self::assertSame(
            'bounce+vy1@example.test',
            EnvelopeSender::build('vy1', 'No Reply <office@Example.Test>')->address,
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unusableDomains(): iterable
    {
        yield 'nothing configured' => [''];
        yield 'dotless' => ['localhost'];
        yield 'a bare label with an at sign' => ['@localhost'];
        yield 'a space inside' => ['exa mple.test'];
        yield 'a hyphen at the label edge' => ['-example.test'];
        yield 'a numeric top level' => ['example.123'];
        yield 'an address literal' => ['[127.0.0.1]'];
        yield 'a header break' => ["example.test\r\nRCPT TO: <victim@example.test>"];
        yield 'not ascii' => ['exämple.test'];
    }

    /**
     * @dataProvider unusableDomains
     */
    public function testAnUnusableDomainYieldsNoAddressAndANamedReason(string $domain): void
    {
        $sender = EnvelopeSender::build('vy3', $domain);

        self::assertNull($sender->address);
        self::assertFalse($sender->isUsable());
        self::assertIsString($sender->reason);
    }

    public function testAPrefixCarryingTheSeparatorIsRefusedSoTheTagStaysRecoverable(): void
    {
        $sender = EnvelopeSender::build('vy3', 'example.test', 'bounce+extra');

        self::assertNull($sender->address);
        self::assertSame(EnvelopeSender::REASON_PREFIX_INVALID, $sender->reason);
    }

    public function testALocalPartOverSixtyFourOctetsIsRefused(): void
    {
        $sender = EnvelopeSender::build('vy1', 'example.test', str_repeat('b', 63));

        self::assertNull($sender->address);
        self::assertSame(EnvelopeSender::REASON_TOO_LONG, $sender->reason);
    }

    public function testTheTagIsRecoveredFromTheAddress(): void
    {
        $address = EnvelopeSender::build('vy4211', 'example.test')->address;

        self::assertIsString($address);
        self::assertSame('vy4211', EnvelopeSender::tagOf($address));
    }

    public function testTheTagIsRecoveredWhateverThePrefixWas(): void
    {
        $address = EnvelopeSender::build('vy8', 'example.test', 'return.path')->address;

        self::assertIsString($address);
        self::assertSame('vy8', EnvelopeSender::tagOf($address));
    }

    public function testAnAddressCarryingNoSubaddressBelongsToSomebodyElse(): void
    {
        self::assertNull(EnvelopeSender::tagOf('postmaster@example.test'));
        self::assertNull(EnvelopeSender::tagOf('bounce+@example.test'));
        self::assertNull(EnvelopeSender::tagOf('not an address'));
    }

    /**
     * A report quotes the reverse path inside angle brackets as often as not.
     */
    public function testAQuotedReversePathStillYieldsItsTag(): void
    {
        self::assertSame('vy12', EnvelopeSender::tagOf(' <bounce+vy12@example.test> '));
    }
}
