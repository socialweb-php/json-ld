<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Rdf;

use SocialWeb\JsonLd\Rdf\Vocabulary;
use SocialWeb\Rdf\Iri;
use SocialWeb\Rdf\Vocabulary\Rdf;
use SocialWeb\Rdf\Vocabulary\Xsd;
use SocialWeb\Test\JsonLd\TestCase;

use function str_starts_with;

class VocabularyTest extends TestCase
{
    public function testNamespacesAgreeWithTheRdfPackage(): void
    {
        $this->assertTrue(str_starts_with(Rdf::LANG_STRING, Vocabulary::RDF));
        $this->assertTrue(str_starts_with(Xsd::STRING, Vocabulary::XSD));
    }

    public function testEveryTermIsAWellFormedIri(): void
    {
        $terms = [
            Vocabulary::RDF_TYPE,
            Vocabulary::RDF_FIRST,
            Vocabulary::RDF_REST,
            Vocabulary::RDF_NIL,
            Vocabulary::RDF_JSON,
            Vocabulary::RDF_VALUE,
            Vocabulary::RDF_LANGUAGE,
            Vocabulary::RDF_DIRECTION,
            Vocabulary::XSD_INTEGER,
            Vocabulary::XSD_DOUBLE,
            Vocabulary::XSD_BOOLEAN,
            Vocabulary::I18N . 'en_ltr',
        ];

        foreach ($terms as $term) {
            $this->assertSame($term, (new Iri($term))->value);
        }
    }
}
