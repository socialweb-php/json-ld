<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Utf16;

use function bin2hex;

class Utf16Test extends TestCase
{
    /**
     * One sample at each boundary of the UTF-8 encoding lengths and of the
     * surrogate range, with the expected UTF-16 big-endian bytes in hexadecimal
     */
    #[DataProvider('samples')]
    public function testEncodesAsUtf16BigEndian(string $text, string $expectedHex): void
    {
        $this->assertSame($expectedHex, bin2hex(Utf16::encode($text)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function samples(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'U+0000' => ["\x00", '0000'];
        yield 'U+0041 A' => ['A', '0041'];
        yield 'U+007F, last one-byte' => ["\x7f", '007f'];
        yield 'U+0080, first two-byte' => ["\xc2\x80", '0080'];
        yield 'U+00E9 é' => ['é', '00e9'];
        yield 'U+07FF, last two-byte' => ["\xdf\xbf", '07ff'];
        yield 'U+0800, first three-byte' => ["\xe0\xa0\x80", '0800'];
        yield 'U+20AC euro sign' => ['€', '20ac'];
        yield 'U+D7FF, before the surrogates' => ["\xed\x9f\xbf", 'd7ff'];
        yield 'U+E000, after the surrogates' => ["\xee\x80\x80", 'e000'];
        yield 'U+FFFD' => ["\xef\xbf\xbd", 'fffd'];
        yield 'U+00E9 followed by A' => ['éA', '00e90041'];
        yield 'U+FFFD followed by A' => ["\xef\xbf\xbdA", 'fffd0041'];
        yield 'U+FFFF, last three-byte' => ["\xef\xbf\xbf", 'ffff'];
        yield 'U+10000, first four-byte' => ["\xf0\x90\x80\x80", 'd800dc00'];
        yield 'U+103FF, low surrogate at its maximum' => ["\xf0\x90\x8f\xbf", 'd800dfff'];
        yield 'U+10400, high surrogate steps' => ["\xf0\x90\x90\x80", 'd801dc00'];
        yield 'U+1F602 face with tears of joy' => ['😂', 'd83dde02'];
        yield 'U+40000, lead byte F1' => ["\xf1\x80\x80\x80", 'd8c0dc00'];
        yield 'U+10FFFF, last code point' => ["\xf4\x8f\xbf\xbf", 'dbffdfff'];
        yield 'mixed' => ["a\xc2\x80\xf0\x90\x80\x80b", '00610080d800dc000062'];
    }

    public function testRejectsAStringThatIsNotUtf8(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageIsOrContains('A string must be valid UTF-8 to be encoded as UTF-16');

        Utf16::encode("\xff");
    }
}
