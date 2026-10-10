<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Importer;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Infrastructure\Xml\XmlNode;

/**
 * The reader every entry of a Moodle backup goes through. A .mbz is an upload
 * from anywhere, so the entry that reaches this class is written by whoever
 * built the archive: the doctype refusal is what stops an XXE read of the
 * server's files and an entity expansion that ends the process, and it has to
 * hold whatever encoding the entry is written in.
 */
final class XmlNodeTest extends TestCase
{
    private const SECRET = 'THE-PRIVATE-KEY-NOBODY-ASKED-FOR';

    private string $secretFile = '';

    protected function setUp(): void
    {
        $this->secretFile = tempnam(sys_get_temp_dir(), 'vidyalms-xxe-') ?: '';
        self::assertNotSame('', $this->secretFile);
        file_put_contents($this->secretFile, self::SECRET);
    }

    protected function tearDown(): void
    {
        if ($this->secretFile !== '' && is_file($this->secretFile)) {
            unlink($this->secretFile);
        }
    }

    public function testItReadsMoodlesUntypedText(): void
    {
        $node = XmlNode::parse(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<course id="7"><fullname>Tea</fullname><summary>$@NULL@$</summary>'
            . '<numsections>3</numsections><grade>2.0000000</grade>'
            . '<intro><![CDATA[<p>Steep</p>]]></intro></course>',
            'course/course.xml',
        );

        self::assertSame('Tea', $node->text('fullname'));
        self::assertNull($node->text('summary'), 'Moodle\'s null marker is a null.');
        self::assertSame(3, $node->int('numsections'));
        self::assertSame(2.0, $node->float('grade'));
        self::assertSame(7, $node->attributeInt('id'));
        self::assertSame('<p>Steep</p>', $node->text('intro'), 'CDATA is text, as LIBXML_NOCDATA asks.');
    }

    /**
     * The byte-level refusal this replaces looked for the ASCII "<!DOCTYPE".
     * A UTF-16 entry spells it "<\0!\0D\0..." and walked straight past, while
     * libxml read the byte order mark and parsed the document its author
     * meant. The question is asked of the parsed document now, so the answer
     * no longer depends on the encoding the attacker chose.
     */
    public function testAUtf16EntryCarryingADoctypeAndAnExternalEntityIsRefused(): void
    {
        $document = '<?xml version="1.0" encoding="UTF-16"?>'
            . '<!DOCTYPE moodle_backup [<!ENTITY xxe SYSTEM "' . $this->secretUri() . '">]>'
            . '<moodle_backup><information><name>&xxe;</name></information></moodle_backup>';

        self::assertFalse(
            stripos(self::utf16($document), '<!DOCTYPE') !== false,
            'The fixture is only a test of the fix if a byte search cannot see its doctype.',
        );

        try {
            XmlNode::parse(self::utf16($document), 'moodle_backup.xml');
            self::fail('A document declaring a doctype must be refused whatever it is encoded in.');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('carries a doctype declaration', $refused->getMessage());
            self::assertStringNotContainsString(self::SECRET, $refused->getMessage());
        }
    }

    public function testAUtf16EntryWithoutADoctypeIsStillReadable(): void
    {
        $node = XmlNode::parse(
            self::utf16('<?xml version="1.0" encoding="UTF-16"?><course id="7"><fullname>Chai</fullname></course>'),
            'course/course.xml',
        );

        self::assertSame('Chai', $node->text('fullname'), 'Refusing every UTF-16 entry would be a different bug.');
        self::assertSame(7, $node->attributeInt('id'));
    }

    /**
     * The same document in UTF-8, which the byte search did catch, so the
     * refusal is not traded away for the encoding fix.
     */
    public function testAUtf8EntryCarryingADoctypeIsRefused(): void
    {
        $this->expectExceptionMessage('carries a doctype declaration');

        XmlNode::parse(
            '<!DOCTYPE moodle_backup [<!ENTITY xxe SYSTEM "' . $this->secretUri() . '">]>'
            . '<moodle_backup><name>&xxe;</name></moodle_backup>',
            'moodle_backup.xml',
        );
    }

    /**
     * An expansion bomb declares its entities in a doctype, so the doctype
     * refusal is what stops it, in any encoding and before anything is
     * expanded. The declaration alone is enough to refuse the document: this
     * one would expand to nothing worth having, and it is still refused.
     */
    public function testADeclaredEntityIsRefusedWithItsDocumentRatherThanExpanded(): void
    {
        try {
            XmlNode::parse(
                self::utf16(
                    '<?xml version="1.0" encoding="UTF-16"?>'
                    . '<!DOCTYPE lolz [<!ENTITY lol "EXPANDED">]><lolz>&lol;</lolz>',
                ),
                'moodle_backup.xml',
            );
            self::fail('A document declaring entities must be refused.');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('carries a doctype declaration', $refused->getMessage());
            self::assertStringNotContainsString('EXPANDED', $refused->getMessage());
        }
    }

    /**
     * The nested form of the same bomb, which libxml refuses on its own by
     * detecting the reference loop. Either refusal is a refusal; what must
     * never happen is a document that parses and expands.
     */
    public function testANestedExpansionBombIsRefused(): void
    {
        $bomb = '<?xml version="1.0" encoding="UTF-16"?>'
            . '<!DOCTYPE lolz [<!ENTITY lol "lol">'
            . '<!ENTITY lol1 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">'
            . '<!ENTITY lol2 "&lol1;&lol1;&lol1;&lol1;&lol1;&lol1;&lol1;&lol1;&lol1;&lol1;">]>'
            . '<lolz>&lol2;</lolz>';

        $this->expectException(\RuntimeException::class);

        XmlNode::parse(self::utf16($bomb), 'moodle_backup.xml');
    }

    /**
     * With no doctype there is nowhere to declare an entity, so a reference
     * to one is an unreadable document rather than a value: nothing resolves
     * the name, and no file is read looking for it.
     */
    public function testAnEntityReferenceWithNothingToDeclareItIsUnreadable(): void
    {
        try {
            XmlNode::parse('<moodle_backup><name>&xxe;</name></moodle_backup>', 'moodle_backup.xml');
            self::fail('An undeclared entity leaves the document unreadable.');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('is not readable XML', $refused->getMessage());
            self::assertStringNotContainsString(self::SECRET, $refused->getMessage());
        }
    }

    public function testAnEmptyEntryIsAnUnreadableDocumentAndNotACrash(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not readable XML');

        XmlNode::parse('', 'course/course.xml');
    }

    /**
     * The parse installs an entity loader that answers nothing and puts
     * libxml's own back afterwards, and it must do so however the parse ends.
     */
    public function testTheEntityLoaderIsRestoredAfterARefusal(): void
    {
        $called = false;
        libxml_set_external_entity_loader(static function () use (&$called): ?string {
            $called = true;

            return null;
        });

        try {
            XmlNode::parse('<not xml', 'course/course.xml');
            self::fail('Malformed XML is refused.');
        } catch (\RuntimeException) {
            // The refusal is the point of the other tests; this one is about
            // what the process is left with afterwards.
        } finally {
            libxml_set_external_entity_loader(null);
        }

        self::assertFalse($called, 'The parse must not resolve entities through whatever loader it found.');
    }

    private function secretUri(): string
    {
        return 'file:///' . str_replace(\DIRECTORY_SEPARATOR, '/', $this->secretFile);
    }

    private static function utf16(string $utf8): string
    {
        $converted = iconv('UTF-8', 'UTF-16LE', $utf8);
        self::assertIsString($converted);

        return "\xFF\xFE" . $converted;
    }
}
