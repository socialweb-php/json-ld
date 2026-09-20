<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Expansion;

use SocialWeb\JsonLd\Expansion\ExpansionResult;
use SocialWeb\Test\JsonLd\TestCase;

use function array_keys;

class ExpansionResultTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $result = new ExpansionResult();

        $this->assertSame([], $result->entries());
        $this->assertFalse($result->has('@id'));
        $this->assertFalse($result->has('@reverse'));
        $this->assertNull($result->get('@id'));
    }

    public function testKeepsKeywordEntriesIncludingNull(): void
    {
        $result = new ExpansionResult();

        $result->set('@id', 'ex:a');
        $result->set('@value', null);
        $result->set('@id', 'ex:b');

        $this->assertTrue($result->has('@id'));
        $this->assertTrue($result->has('@value'));
        $this->assertFalse($result->has('@type'));
        $this->assertSame('ex:b', $result->get('@id'));
        $this->assertSame(['@id' => 'ex:b', '@value' => null], $result->entries());
    }

    public function testAddsValuesToAPropertyAsAList(): void
    {
        $one = (object) ['@value' => 1];
        $two = (object) ['@value' => 2];
        $three = (object) ['@value' => 3];
        $result = new ExpansionResult();

        $result->add('ex:p', $one);
        $result->add('ex:p', [$two, $three]);
        $result->add('ex:q', []);
        $result->add('ex:p', []);

        $this->assertSame(['ex:p' => [$one, $two, $three], 'ex:q' => []], $result->entries());
        $this->assertFalse($result->has('ex:p'));
    }

    public function testKeepsTheReverseMapApartUntilTheEnd(): void
    {
        $a = (object) ['@id' => 'ex:a'];
        $b = (object) ['@id' => 'ex:b'];
        $result = new ExpansionResult();

        $result->addReverse('ex:parent', $a);

        $this->assertTrue($result->has('@reverse'));
        $this->assertNull($result->get('@reverse'));

        $result->addReverse('ex:parent', $b);
        $result->addReverse('ex:other', $a);
        $result->set('@id', 'ex:c');
        $result->add('ex:p', $a);

        $this->assertEquals(
            [
                '@id' => 'ex:c',
                'ex:p' => [$a],
                '@reverse' => (object) ['ex:parent' => [$a, $b], 'ex:other' => [$a]],
            ],
            $result->entries(),
        );
    }

    public function testReturnsEntriesInCodePointOrder(): void
    {
        $a = (object) ['@id' => 'ex:a'];
        $result = new ExpansionResult();

        $result->add('ex:z', $a);
        $result->addReverse('ex:y', $a);
        $result->addReverse('ex:b', $a);
        $result->set('@type', ['ex:T']);
        $result->add('ex:B', $a);
        $result->add('Ex:b', $a);
        $result->set('@id', 'ex:c');

        $entries = $result->entries();

        $this->assertSame(['@id', '@reverse', '@type', 'Ex:b', 'ex:B', 'ex:z'], array_keys($entries));
        $this->assertSame(['ex:b', 'ex:y'], array_keys((array) $entries['@reverse']));
    }
}
