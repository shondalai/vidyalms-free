<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\HtmlPolicy;

/**
 * The one server-side answer to what a stored body may carry.
 *
 * The player sanitises the stimulus body in the browser, which protects the
 * browser that runs the sanitiser and nothing else: a body written by an
 * interchange import reached the database unexamined, and every other consumer
 * of that column inherited whatever the foreign document carried.
 */
final class HtmlPolicyTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bodies(): array
    {
        return [
            'a paragraph survives' => ['<p>Hello</p>', '<p>Hello</p>'],
            'emphasis survives' => ['<p>A <em>word</em></p>', '<p>A <em>word</em></p>'],
            'a list survives' => ['<ul><li>One</li></ul>', '<ul><li>One</li></ul>'],
            'a script goes with its payload' => ['<p>A</p><script>alert(1)</script>', '<p>A</p>'],
            'a style goes with its payload' => ['<style>body{}</style><p>A</p>', '<p>A</p>'],
            'an iframe goes' => ['<iframe src="https://evil.example"></iframe><p>A</p>', '<p>A</p>'],
            'an event handler goes' => ['<p onclick="alert(1)">A</p>', '<p>A</p>'],
            'a javascript href goes' => ['<a href="javascript:alert(1)">A</a>', '<a>A</a>'],
            'a split javascript scheme goes' => ['<a href="java&#x09;script:alert(1)">A</a>', '<a>A</a>'],
            'a newline javascript scheme goes' => ['<a href="java&#10;script:alert(1)">A</a>', '<a>A</a>'],
            'a backslash authority goes' => ['<a href="\\\\evil.example">A</a>', '<a>A</a>'],
            'an http href stays' => ['<a href="https://ok.example">A</a>', '<a href="https://ok.example">A</a>'],
            'a relative src stays' => ['<img src="media/a.png" alt="A">', '<img src="media/a.png" alt="A">'],
            'a data uri image goes' => ['<img src="data:text/html,x" alt="A">', '<img alt="A">'],
            'an unlisted wrapper keeps its text' => ['<marquee><p>A</p></marquee>', '<p>A</p>'],
        ];
    }

    /**
     * @dataProvider bodies
     */
    public function testOnlyTheAllowListSurvives(string $body, string $expected): void
    {
        self::assertSame($expected, HtmlPolicy::safe($body));
    }

    public function testPlainTextIsTheTextAndNothingElse(): void
    {
        self::assertSame('A word', HtmlPolicy::plain('<p>A <em>word</em></p>'));
        self::assertSame('A word', HtmlPolicy::plain('A word'));
        self::assertSame('', HtmlPolicy::plain('<script>alert(1)</script>'));
        self::assertSame('5 < 6', HtmlPolicy::plain('5 &lt; 6'));
    }

    public function testAStringWithNoMarkupIsUntouched(): void
    {
        self::assertSame('Plain words', HtmlPolicy::safe('Plain words'));
        self::assertSame('', HtmlPolicy::safe(''));
    }
}
