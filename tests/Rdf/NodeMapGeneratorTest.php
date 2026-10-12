<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Rdf;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\JsonLd\DataLossCondition;
use SocialWeb\JsonLd\ErrorCode;
use SocialWeb\JsonLd\Exception\DataLoss;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Options;
use SocialWeb\JsonLd\Processor;
use SocialWeb\JsonLd\Rdf\BlankNodeIssuer;
use SocialWeb\JsonLd\Rdf\JsonCanonicalizer;
use SocialWeb\JsonLd\Rdf\NodeMap;
use SocialWeb\JsonLd\Rdf\NodeMapGenerator;
use SocialWeb\Test\JsonLd\TestCase;
use stdClass;

use function array_column;
use function array_is_list;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Expected node maps are written as JSON, one entry for each graph whose value
 * holds the nodes of the graph by identifier, and compared as canonical JSON,
 * so the order of map entries does not matter and the order of lists does.
 */
class NodeMapGeneratorTest extends TestCase
{
    /**
     * Example 137 of JSON-LD 1.1, whose flattened form is example 138
     */
    private const string PEOPLE = '{
        "@context": {"name": "http://xmlns.com/foaf/0.1/name", "knows": "http://xmlns.com/foaf/0.1/knows"},
        "@id": "http://me.markus-lanthaler.com/",
        "name": "Markus Lanthaler",
        "knows": [
            {"@id": "http://manu.sporny.org/about#manu", "name": "Manu Sporny"},
            {"name": "Dave Longley"}
        ]
    }';

    private const string PEOPLE_NODE_MAP = '{"@default": {
        "http://me.markus-lanthaler.com/": {
            "@id": "http://me.markus-lanthaler.com/",
            "http://xmlns.com/foaf/0.1/name": [{"@value": "Markus Lanthaler"}],
            "http://xmlns.com/foaf/0.1/knows": [{"@id": "http://manu.sporny.org/about#manu"}, {"@id": "_:b0"}]
        },
        "http://manu.sporny.org/about#manu": {
            "@id": "http://manu.sporny.org/about#manu",
            "http://xmlns.com/foaf/0.1/name": [{"@value": "Manu Sporny"}]
        },
        "_:b0": {"@id": "_:b0", "http://xmlns.com/foaf/0.1/name": [{"@value": "Dave Longley"}]}
    }}';

    public function testGeneratesTheNodeMapOfAnExpandedDocument(): void
    {
        $expanded = (new Processor(new Options(base: 'https://example.com/')))->expand(self::PEOPLE);

        $this->assertNodeMap(
            self::PEOPLE_NODE_MAP,
            NodeMapGenerator::generate($expanded->jsonSerialize(), new BlankNodeIssuer(), false),
        );
    }

    #[DataProvider('documents')]
    public function testGenerates(string $expected, string $document): void
    {
        $this->assertNodeMap(
            $expected,
            NodeMapGenerator::generate(self::nodes($document), new BlankNodeIssuer(), false),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'no nodes' => ['{"@default": {}}', '[]'];

        yield 'a node with only an identifier is kept' => [
            '{"@default": {"https://example.com/a": {"@id": "https://example.com/a"}}}',
            '[{"@id": "https://example.com/a"}]',
        ];

        yield 'a property with no values is kept' => [
            '{"@default": {"https://example.com/a": {"@id": "https://example.com/a", "https://example.com/p": []}}}',
            '[{"@id": "https://example.com/a", "https://example.com/p": []}]',
        ];

        yield 'example 137 of JSON-LD 1.1 in expanded form' => [
            self::PEOPLE_NODE_MAP,
            '[{
                "@id": "http://me.markus-lanthaler.com/",
                "http://xmlns.com/foaf/0.1/name": [{"@value": "Markus Lanthaler"}],
                "http://xmlns.com/foaf/0.1/knows": [
                    {
                        "@id": "http://manu.sporny.org/about#manu",
                        "http://xmlns.com/foaf/0.1/name": [{"@value": "Manu Sporny"}]
                    },
                    {"http://xmlns.com/foaf/0.1/name": [{"@value": "Dave Longley"}]}
                ]
            }]',
        ];

        yield 'example 109 of JSON-LD 1.1, an included block, in expanded form' => [
            '{"@default": {
                "http://example.org/org-1": {
                    "@id": "http://example.org/org-1",
                    "http://example.org/members": [
                        {"@id": "http://example.org/person-1"},
                        {"@id": "http://example.org/person-2"},
                        {"@id": "http://example.org/person-3"}
                    ]
                },
                "http://example.org/person-1": {
                    "@id": "http://example.org/person-1",
                    "http://example.org/name": [{"@value": "Manu Sporny"}],
                    "http://example.org/classification": [{"@id": "http://example.org/employee"}]
                },
                "http://example.org/person-2": {
                    "@id": "http://example.org/person-2",
                    "http://example.org/name": [{"@value": "Dave Longley"}],
                    "http://example.org/classification": [{"@id": "http://example.org/employee"}]
                },
                "http://example.org/person-3": {
                    "@id": "http://example.org/person-3",
                    "http://example.org/name": [{"@value": "Gregg Kellogg"}],
                    "http://example.org/classification": [{"@id": "http://example.org/contractor"}]
                },
                "http://example.org/employee": {
                    "@id": "http://example.org/employee",
                    "http://example.org/label": [{"@value": "An Employee"}]
                },
                "http://example.org/contractor": {
                    "@id": "http://example.org/contractor",
                    "http://example.org/label": [{"@value": "A Contractor"}]
                }
            }}',
            '[{
                "@id": "http://example.org/org-1",
                "http://example.org/members": [{
                    "@id": "http://example.org/person-1",
                    "http://example.org/name": [{"@value": "Manu Sporny"}],
                    "http://example.org/classification": [{"@id": "http://example.org/employee"}]
                }, {
                    "@id": "http://example.org/person-2",
                    "http://example.org/name": [{"@value": "Dave Longley"}],
                    "http://example.org/classification": [{"@id": "http://example.org/employee"}]
                }, {
                    "@id": "http://example.org/person-3",
                    "http://example.org/name": [{"@value": "Gregg Kellogg"}],
                    "http://example.org/classification": [{"@id": "http://example.org/contractor"}]
                }],
                "@included": [{
                    "@id": "http://example.org/employee",
                    "http://example.org/label": [{"@value": "An Employee"}]
                }, {
                    "@id": "http://example.org/contractor",
                    "http://example.org/label": [{"@value": "A Contractor"}]
                }]
            }]',
        ];

        yield 'example 111 of JSON-LD 1.1, disconnected nodes in an included block, in expanded form' => [
            '{"@default": {
                "_:b0": {"@id": "_:b0"},
                "http://manu.sporny.org/about#manu": {
                    "@id": "http://manu.sporny.org/about#manu",
                    "@type": ["http://xmlns.com/foaf/0.1/Person"],
                    "http://xmlns.com/foaf/0.1/name": [{"@value": "Manu Sporny"}],
                    "http://xmlns.com/foaf/0.1/knows": [{"@id": "https://greggkellogg.net/foaf#me"}]
                },
                "https://greggkellogg.net/foaf#me": {
                    "@id": "https://greggkellogg.net/foaf#me",
                    "@type": ["http://xmlns.com/foaf/0.1/Person"],
                    "http://xmlns.com/foaf/0.1/name": [{"@value": "Gregg Kellogg"}],
                    "http://xmlns.com/foaf/0.1/knows": [{"@id": "http://manu.sporny.org/about#manu"}]
                }
            }}',
            '[{"@included": [{
                "@id": "http://manu.sporny.org/about#manu",
                "@type": ["http://xmlns.com/foaf/0.1/Person"],
                "http://xmlns.com/foaf/0.1/name": [{"@value": "Manu Sporny"}],
                "http://xmlns.com/foaf/0.1/knows": [{"@id": "https://greggkellogg.net/foaf#me"}]
            }, {
                "@id": "https://greggkellogg.net/foaf#me",
                "@type": ["http://xmlns.com/foaf/0.1/Person"],
                "http://xmlns.com/foaf/0.1/name": [{"@value": "Gregg Kellogg"}],
                "http://xmlns.com/foaf/0.1/knows": [{"@id": "http://manu.sporny.org/about#manu"}]
            }]}]',
        ];

        yield 'example 113 of JSON-LD 1.1, a reverse property, in expanded form' => [
            '{"@default": {
                "http://example.org/#homer": {
                    "@id": "http://example.org/#homer",
                    "http://example.com/vocab#name": [{"@value": "Homer"}]
                },
                "http://example.org/#bart": {
                    "@id": "http://example.org/#bart",
                    "http://example.com/vocab#name": [{"@value": "Bart"}],
                    "http://example.com/vocab#parent": [{"@id": "http://example.org/#homer"}]
                },
                "http://example.org/#lisa": {
                    "@id": "http://example.org/#lisa",
                    "http://example.com/vocab#name": [{"@value": "Lisa"}],
                    "http://example.com/vocab#parent": [{"@id": "http://example.org/#homer"}]
                }
            }}',
            '[{
                "@id": "http://example.org/#homer",
                "http://example.com/vocab#name": [{"@value": "Homer"}],
                "@reverse": {"http://example.com/vocab#parent": [
                    {"@id": "http://example.org/#bart", "http://example.com/vocab#name": [{"@value": "Bart"}]},
                    {"@id": "http://example.org/#lisa", "http://example.com/vocab#name": [{"@value": "Lisa"}]}
                ]}
            }]',
        ];

        yield 'blank nodes are relabeled in the order the algorithm meets them' => [
            '{"@default": {
                "_:b1": {
                    "@id": "_:b1",
                    "@type": ["_:b0", "https://example.com/T"],
                    "_:b2": [{"@id": "_:b1"}, {"@id": "_:b3"}],
                    "https://example.com/q": [{"@value": "x", "@type": "_:b4"}]
                },
                "_:b3": {"@id": "_:b3"}
            }}',
            '[{
                "@id": "_:a",
                "@type": ["_:t", "https://example.com/T"],
                "https://example.com/q": [{"@value": "x", "@type": "_:d"}],
                "_:p": [{"@id": "_:a"}, {"@id": "_:c"}]
            }]',
        ];

        yield 'a blank node named in a type and as a subject is one blank node' => [
            '{"@default": {
                "https://example.com/a": {"@id": "https://example.com/a", "@type": ["_:b0"]},
                "_:b0": {"@id": "_:b0", "https://example.com/p": [{"@value": 1}]}
            }}',
            '[
                {"@id": "https://example.com/a", "@type": ["_:t"]},
                {"@id": "_:t", "https://example.com/p": [{"@value": 1}]}
            ]',
        ];

        yield 'a repeated value, reference, or type is kept once, and a repeated list is kept' => [
            '{"@default": {
                "https://example.com/a": {
                    "@id": "https://example.com/a",
                    "@type": ["https://example.com/T", "https://example.com/U"],
                    "https://example.com/p": [
                        {"@value": 1},
                        {"@value": "1"},
                        {"@id": "https://example.com/b"},
                        {"@value": "x", "@direction": "ltr"},
                        {"@value": "x", "@direction": "rtl"},
                        {"@type": "@json", "@value": {"a": [1], "b": 1}},
                        {"@list": [{"@value": 1}]},
                        {"@list": [{"@value": 1}]}
                    ]
                },
                "https://example.com/b": {"@id": "https://example.com/b", "https://example.com/q": [{"@value": 2}]}
            }}',
            '[{
                "@id": "https://example.com/a",
                "@type": ["https://example.com/T", "https://example.com/U", "https://example.com/T"],
                "https://example.com/p": [
                    {"@value": 1},
                    {"@value": 1.0},
                    {"@value": "1"},
                    {"@value": 1},
                    {"@id": "https://example.com/b"},
                    {"@id": "https://example.com/b", "https://example.com/q": [{"@value": 2}]},
                    {"@value": "x", "@direction": "ltr"},
                    {"@value": "x", "@direction": "rtl"},
                    {"@type": "@json", "@value": {"b": 1, "a": [1]}},
                    {"@type": "@json", "@value": {"a": [1], "b": 1}},
                    {"@list": [{"@value": 1}]},
                    {"@list": [{"@value": 1}]}
                ]
            }]',
        ];

        yield 'a node named twice under a reverse property refers to the subject once' => [
            '{"@default": {
                "https://example.com/a": {"@id": "https://example.com/a"},
                "https://example.com/b": {
                    "@id": "https://example.com/b",
                    "https://example.com/p": [{"@id": "https://example.com/a"}],
                    "https://example.com/q": [{"@value": 1}]
                }
            }}',
            '[{"@id": "https://example.com/a", "@reverse": {"https://example.com/p": [
                {"@id": "https://example.com/b"},
                {"@id": "https://example.com/b", "https://example.com/q": [{"@value": 1}]}
            ]}}]',
        ];

        yield 'a typed value inside a list is relabeled and kept in its place' => [
            '{"@default": {
                "https://example.com/a": {
                    "@id": "https://example.com/a",
                    "https://example.com/p": [{"@list": [
                        {"@value": "x", "@type": "_:b0"},
                        {"@value": "y", "@type": "https://example.com/T"}
                    ]}]
                }
            }}',
            '[{"@id": "https://example.com/a", "https://example.com/p": [{"@list": [
                {"@value": "x", "@type": "_:t"},
                {"@value": "y", "@type": "https://example.com/T"}
            ]}]}]',
        ];

        yield 'the items of a list keep their order, and a list may hold lists and nodes' => [
            '{"@default": {
                "https://example.com/a": {
                    "@id": "https://example.com/a",
                    "https://example.com/p": [{"@list": [
                        {"@value": 1},
                        {"@list": [{"@id": "https://example.com/b"}, {"@list": []}]},
                        {"@id": "_:b0"},
                        {"@value": 1}
                    ]}]
                },
                "https://example.com/b": {"@id": "https://example.com/b", "https://example.com/q": [{"@value": 2}]},
                "_:b0": {"@id": "_:b0"}
            }}',
            '[{"@id": "https://example.com/a", "https://example.com/p": [{"@list": [
                {"@value": 1},
                {"@list": [{"@id": "https://example.com/b", "https://example.com/q": [{"@value": 2}]}, {"@list": []}]},
                {"@id": "_:x"},
                {"@value": 1}
            ]}]}]',
        ];

        yield 'named graphs, a graph as the value of a property, and an empty graph' => [
            '{
                "@default": {
                    "https://example.com/g": {"@id": "https://example.com/g"},
                    "_:b0": {"@id": "_:b0"},
                    "https://example.com/e": {"@id": "https://example.com/e"}
                },
                "https://example.com/g": {
                    "https://example.com/a": {
                        "@id": "https://example.com/a",
                        "https://example.com/p": [{"@id": "https://example.com/h"}]
                    },
                    "https://example.com/h": {"@id": "https://example.com/h"}
                },
                "https://example.com/h": {
                    "https://example.com/c": {"@id": "https://example.com/c", "https://example.com/q": [{"@value": 1}]}
                },
                "_:b0": {"https://example.com/d": {"@id": "https://example.com/d"}}
            }',
            '[
                {"@id": "https://example.com/g", "@graph": [{
                    "@id": "https://example.com/a",
                    "https://example.com/p": [{"@id": "https://example.com/h", "@graph": [
                        {"@id": "https://example.com/c", "https://example.com/q": [{"@value": 1}]}
                    ]}]
                }]},
                {"@graph": [{"@id": "https://example.com/d"}]},
                {"@id": "https://example.com/e", "@graph": []}
            ]',
        ];

        yield 'an index is kept on the node, and on a value' => [
            '{"@default": {
                "https://example.com/a": {
                    "@id": "https://example.com/a",
                    "@index": "i",
                    "https://example.com/p": [{"@id": "https://example.com/a"}, {"@value": 1, "@index": "k"}]
                }
            }}',
            '[{
                "@id": "https://example.com/a",
                "@index": "i",
                "https://example.com/p": [{"@id": "https://example.com/a", "@index": "i"}, {"@value": 1, "@index": "k"}]
            }]',
        ];

        yield 'a null identifier, and a null type, which lenient expansion leaves behind' => [
            '{"@default": {
                "": {
                    "@id": "",
                    "@type": [null, "https://example.com/T"],
                    "https://example.com/p": [{"@id": ""}, {"@id": "https://example.com/b"}],
                    "https://example.com/r": []
                },
                "https://example.com/b": {"@id": "https://example.com/b", "https://example.com/q": [{"@id": ""}]}
            }}',
            '[{
                "@id": null,
                "@type": [null, "https://example.com/T"],
                "https://example.com/p": [
                    {"@id": null},
                    {
                        "@id": "https://example.com/b",
                        "https://example.com/q": [{"@id": null, "https://example.com/r": []}]
                    }
                ]
            }]',
        ];

        yield 'the same identifier in two graphs names two nodes' => [
            '{
                "@default": {
                    "https://example.com/a": {
                        "@id": "https://example.com/a",
                        "https://example.com/p": [{"@value": 1}]
                    }
                },
                "https://example.com/a": {
                    "https://example.com/a": {
                        "@id": "https://example.com/a",
                        "https://example.com/p": [{"@value": 2}]
                    }
                }
            }',
            '[{
                "@id": "https://example.com/a",
                "https://example.com/p": [{"@value": 1}],
                "@graph": [{"@id": "https://example.com/a", "https://example.com/p": [{"@value": 2}]}]
            }]',
        ];

        yield 'the value of a JSON literal is data, whatever its keys look like' => [
            '{"@default": {
                "https://example.com/a": {
                    "@id": "https://example.com/a",
                    "https://example.com/p": [{
                        "@type": "@json",
                        "@value": {"@id": "_:b", "@list": [{"@id": "_:c"}], "@graph": [], "@type": ["_:d"]}
                    }]
                }
            }}',
            '[{
                "@id": "https://example.com/a",
                "https://example.com/p": [{
                    "@type": "@json",
                    "@value": {"@id": "_:b", "@list": [{"@id": "_:c"}], "@graph": [], "@type": ["_:d"]}
                }]
            }]',
        ];

        yield 'a type written as a string, which expansion leaves beside a set' => [
            '{"@default": {
                "https://example.com/a": {
                    "@id": "https://example.com/a",
                    "@type": ["https://example.com/T"],
                    "@set": [{"@value": 1}]
                }
            }}',
            '[{"@id": "https://example.com/a", "@type": "https://example.com/T", "@set": [{"@value": 1}]}]',
        ];

        yield 'a property whose name is only digits' => [
            '{"@default": {"https://example.com/a": {"@id": "https://example.com/a", "123": [{"@value": 1}]}}}',
            '[{"@id": "https://example.com/a", "123": [{"@value": 1}]}]',
        ];
    }

    #[DataProvider('documentsWithTheSameEntriesInAnotherOrder')]
    public function testTheLabelsDoNotDependOnTheOrderOfEntries(string $document, string $reordered): void
    {
        $this->assertSame(
            self::toJson(NodeMapGenerator::generate(self::nodes($document), new BlankNodeIssuer(), false)),
            self::toJson(NodeMapGenerator::generate(self::nodes($reordered), new BlankNodeIssuer(), false)),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documentsWithTheSameEntriesInAnotherOrder(): iterable
    {
        yield 'properties' => [
            '[{"https://example.com/a": [{}], "https://example.com/b": [{}]}]',
            '[{"https://example.com/b": [{}], "https://example.com/a": [{}]}]',
        ];
        yield 'reverse properties' => [
            '[{"@id": "_:x", "@reverse": {"https://example.com/a": [{}], "https://example.com/b": [{}]}}]',
            '[{"@id": "_:x", "@reverse": {"https://example.com/b": [{}], "https://example.com/a": [{}]}}]',
        ];
        yield 'keywords and properties' => [
            '[{"https://example.com/p": [{}], "@graph": [{}], "@included": [{}], "@type": ["_:t"], "@id": "_:s"}]',
            '[{"@id": "_:s", "@type": ["_:t"], "@included": [{}], "@graph": [{}], "https://example.com/p": [{}]}]',
        ];
    }

    public function testLeavesTheDocumentAsItWas(): void
    {
        $nodes = self::nodes(
            '[{
                "@id": "_:a",
                "@type": ["_:t"],
                "@index": "i",
                "@reverse": {"_:r": [{"@id": "_:b"}]},
                "@graph": [{"@id": "_:c"}],
                "@included": [{"@id": "_:d"}],
                "_:p": [{"@value": "x", "@type": "_:u"}, {"@list": [{"@id": "_:e"}]}]
            }]',
        );
        $before = JsonCanonicalizer::canonicalize($nodes);

        NodeMapGenerator::generate($nodes, new BlankNodeIssuer(), false);

        $this->assertSame($before, JsonCanonicalizer::canonicalize($nodes));
    }

    public function testRefusesConflictingIndexes(): void
    {
        try {
            NodeMapGenerator::generate(
                self::nodes(
                    '[
                        {"@id": "https://example.com/a", "@index": "i"},
                        {"@id": "https://example.com/a", "@index": "j"}
                    ]',
                ),
                new BlankNodeIssuer(),
                false,
            );
            $this->fail('Expected a JsonLdError');
        } catch (JsonLdError $exception) {
            $this->assertSame(ErrorCode::ConflictingIndexes, $exception->errorCode);
            $this->assertSame(
                'conflicting indexes: https://example.com/a',
                $exception->getMessage(),
            );
        }
    }

    #[DataProvider('documentsThatAreNotExpanded')]
    public function testRefusesADocumentThatIsNotExpanded(string $document, string $message): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage($message);

        NodeMapGenerator::generate(self::nodes($document), new BlankNodeIssuer(), false);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documentsThatAreNotExpanded(): iterable
    {
        $onlyMaps = 'An expanded document holds only maps and arrays';
        $noProperty = 'A value object or list object must be the value of a property of a node';

        yield 'a scalar' => ['[1]', $onlyMaps];
        yield 'null' => ['[null]', $onlyMaps];
        yield 'a scalar in a list' => [
            '[{"@id": "https://example.com/a", "https://example.com/p": [{"@list": [1]}]}]',
            $onlyMaps,
        ];
        yield 'a value at the top level' => ['[{"@value": 1}]', $noProperty];
        yield 'a list at the top level' => ['[{"@list": []}]', $noProperty];
        yield 'a value in a graph' => ['[{"@id": "https://example.com/g", "@graph": [{"@value": 1}]}]', $noProperty];
        yield 'a list in an included block' => [
            '[{"@id": "https://example.com/a", "@included": [{"@list": []}]}]',
            $noProperty,
        ];
        yield 'a value under a reverse property' => [
            '[{"@id": "https://example.com/a", "@reverse": {"https://example.com/p": [{"@value": 1}]}}]',
            $noProperty,
        ];
        yield 'an identifier that is a number' => [
            '[{"@id": 1}]',
            'The @id of a node object must be a string or null',
        ];
        yield 'an index that is not a string' => [
            '[{"@id": "https://example.com/a", "@index": 1}]',
            'The @index of a node object must be a string',
        ];
        yield 'a reverse map that is an array' => [
            '[{"@id": "https://example.com/a", "@reverse": []}]',
            'The @reverse of a node object must be a map',
        ];
    }

    public function testATypeBesideAListIsDroppedInLenientModeAndRefusedInStrictMode(): void
    {
        $document = '[{"@id": "https://example.com/a", "https://example.com/p": [
            {"@list": [{"@value": 1}], "@type": "https://example.com/T"}
        ]}]';

        $this->assertNodeMap(
            '{"@default": {"https://example.com/a": {
                "@id": "https://example.com/a",
                "https://example.com/p": [{"@list": [{"@value": 1}]}]
            }}}',
            NodeMapGenerator::generate(self::nodes($document), new BlankNodeIssuer(), false),
        );

        try {
            NodeMapGenerator::generate(self::nodes($document), new BlankNodeIssuer(), true);
            $this->fail('Expected a DataLoss');
        } catch (DataLoss $dataLoss) {
            $this->assertSame(DataLossCondition::UndefinedProperty, $dataLoss->condition);
            $this->assertSame('@type', $dataLoss->detail);
        }
    }

    public function testGoesOnIssuingFromTheIssuerItIsGiven(): void
    {
        $issuer = new BlankNodeIssuer();
        $issuer->issue('_:first');

        $map = NodeMapGenerator::generate(
            self::nodes('[{"@id": "_:x", "https://example.com/p": [{"@id": "_:first"}, {}]}]'),
            $issuer,
            false,
        );

        $this->assertSame(['_:b0', '_:b1', '_:b2'], array_column($map->nodes('@default'), 'id'));
        $this->assertSame('_:b3', $issuer->issue());
    }

    public function testReferencesAndListObjectsAreMaps(): void
    {
        $map = NodeMapGenerator::generate(
            self::nodes(
                '[{
                    "@id": "https://example.com/a",
                    "https://example.com/p": [
                        {"@id": "https://example.com/b"},
                        {"@list": [{"@id": "https://example.com/b"}]}
                    ],
                    "@reverse": {"https://example.com/q": [{"@id": "https://example.com/c"}]}
                }]',
            ),
            new BlankNodeIssuer(),
            false,
        );

        [$a, $b, $c] = $map->nodes('@default');
        [$reference, $list] = $a->properties['https://example.com/p'];

        $this->assertInstanceOf(stdClass::class, $reference);
        $this->assertInstanceOf(stdClass::class, $list);

        /** @var list<mixed> $items */
        $items = $list->{'@list'};
        $this->assertInstanceOf(stdClass::class, $items[0]);
        $this->assertInstanceOf(stdClass::class, $c->properties['https://example.com/q'][0]);
        $this->assertSame('https://example.com/b', $b->id);
    }

    public function testKeepsOneOfManyEqualValues(): void
    {
        $values = [];

        for ($i = 0; $i < 20_000; $i++) {
            $values[] = (object) ['@value' => $i % 2];
            $values[] = (object) ['@id' => 'https://example.com/' . $i % 2];
        }

        $map = NodeMapGenerator::generate(
            [(object) ['@id' => 'https://example.com/a', 'https://example.com/p' => $values]],
            new BlankNodeIssuer(),
            false,
        );

        $this->assertCount(4, $map->node('@default', 'https://example.com/a')->properties['https://example.com/p']);
        $this->assertCount(3, $map->nodes('@default'));
    }

    private function assertNodeMap(string $expected, NodeMap $actual): void
    {
        $this->assertSame(
            JsonCanonicalizer::canonicalize(json_decode($expected, flags: JSON_THROW_ON_ERROR)),
            self::toJson($actual),
        );
    }

    /**
     * Writes a node map as canonical JSON, one entry for each graph whose value
     * holds the nodes of the graph by identifier
     */
    private static function toJson(NodeMap $map): string
    {
        $graphs = new stdClass();

        foreach ($map->graphNames() as $graphName) {
            $graph = new stdClass();

            foreach ($map->nodes($graphName) as $node) {
                $entries = ['@id' => $node->id];

                if ($node->types !== []) {
                    $entries['@type'] = $node->types;
                }

                if ($node->index !== null) {
                    $entries['@index'] = $node->index;
                }

                // `+` keeps a property name that is only digits, which PHP
                // stores as an integer key and a spread would renumber.
                $graph->{$node->id} = (object) ($entries + $node->properties);
            }

            $graphs->{$graphName} = $graph;
        }

        return JsonCanonicalizer::canonicalize($graphs);
    }

    /**
     * @return list<mixed>
     */
    private static function nodes(string $document): array
    {
        $nodes = json_decode($document, flags: JSON_THROW_ON_ERROR);

        if (!is_array($nodes) || !array_is_list($nodes)) {
            throw new LogicException('An expanded document is a JSON array');
        }

        return $nodes;
    }
}
