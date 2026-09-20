<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd;

use SocialWeb\JsonLd\ExpandedDocument;
use stdClass;

use function json_encode;
use function str_repeat;

class ExpandedDocumentTest extends TestCase
{
    public function testSerializesToItsNodes(): void
    {
        $node = (object) ['@id' => 'https://example.com/a', 'https://example.com/p' => [(object) ['@value' => 1]]];
        $document = new ExpandedDocument([$node]);

        $this->assertEquals([$node], $document->jsonSerialize());
        $this->assertSame(
            '[{"@id":"https:\/\/example.com\/a","https:\/\/example.com\/p":[{"@value":1}]}]',
            json_encode($document),
        );
    }

    public function testAnEmptyDocumentIsAnEmptyArray(): void
    {
        $document = new ExpandedDocument([]);

        $this->assertSame([], $document->jsonSerialize());
        $this->assertSame('[]', $document->toJson());
    }

    public function testWritesJsonWithoutEscapingSlashesOrUnicodeAndKeepsFloatsAsFloats(): void
    {
        $document = new ExpandedDocument([
            (object) [
                'https://example.com/p' => [
                    (object) ['@value' => 'Zoë ☃'],
                    (object) ['@value' => 1.0],
                    (object) ['@value' => 1],
                    (object) ['@value' => 1.5],
                ],
            ],
        ]);

        $this->assertSame(
            '[{"https://example.com/p":[{"@value":"Zoë ☃"},{"@value":1.0},{"@value":1},{"@value":1.5}]}]',
            $document->toJson(),
        );
    }

    public function testAnEmptyObjectStaysAnObject(): void
    {
        $document = new ExpandedDocument([
            (object) ['https://example.com/p' => [new stdClass(), (object) ['@list' => []]]],
        ]);

        $this->assertSame('[{"https://example.com/p":[{},{"@list":[]}]}]', $document->toJson());
    }

    public function testChangingWhatItReturnsDoesNotChangeTheDocument(): void
    {
        $literal = (object) ['deep' => [1, 2]];
        $value = (object) ['@type' => '@json', '@value' => $literal];
        $document = new ExpandedDocument([(object) ['https://example.com/p' => [$value]]]);
        $before = $document->toJson();

        $node = $document->jsonSerialize()[0];
        $this->assertInstanceOf(stdClass::class, $node);
        $values = $node->{'https://example.com/p'};
        $this->assertIsArray($values);
        $valueCopy = $values[0];
        $this->assertInstanceOf(stdClass::class, $valueCopy);
        $literalCopy = $valueCopy->{'@value'};
        $this->assertInstanceOf(stdClass::class, $literalCopy);

        $this->assertNotSame($value, $valueCopy);
        $this->assertNotSame($literal, $literalCopy);
        $this->assertEquals($value, $valueCopy);

        $literalCopy->deep = 'changed';
        $valueCopy->{'@type'} = 'changed';
        $node->{'https://example.com/q'} = [];

        $this->assertSame($before, $document->toJson());
        $this->assertSame('[{"https://example.com/p":[{"@type":"@json","@value":{"deep":[1,2]}}]}]', $before);
    }

    public function testWritesJsonDeeperThanTheDefaultJsonEncodeDepth(): void
    {
        $node = (object) ['@value' => 'leaf'];

        for ($i = 0; $i < 600; $i++) {
            $node = (object) ['ex:p' => [$node]];
        }

        $document = new ExpandedDocument([$node]);
        $json = $document->toJson();

        // A deep recursive comparison of the decoded structure hits Xdebug's
        // stack-depth guard, so this pins the exact text instead.
        $expected = '[' . str_repeat('{"ex:p":[', 600) . '{"@value":"leaf"}' . str_repeat(']}', 600) . ']';
        $this->assertSame($expected, $json);
    }
}
