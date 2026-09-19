<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialWeb\Test\JsonLd\TestCase;

use function basename;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash;
use function implode;
use function json_encode;
use function mkdir;
use function rmdir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * Runs bin/generate-contexts.php as a caller would
 */
class ContextGeneratorTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../bin/generate-contexts.php';
    private const string RESOURCES = __DIR__ . '/../../resources/contexts';
    private const string BUNDLED = __DIR__ . '/../../src/Context/Bundled';

    private string $resources;
    private string $output;

    protected function setUp(): void
    {
        $this->resources = sys_get_temp_dir() . '/' . uniqid('json-ld-resources-');
        $this->output = sys_get_temp_dir() . '/' . uniqid('json-ld-output-');

        mkdir($this->resources);
        mkdir($this->output);
    }

    protected function tearDown(): void
    {
        foreach ([$this->resources, $this->output] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }

    public function testTheCommittedFilesAreWhatTheGeneratorProduces(): void
    {
        [$status, $lines] = $this->generate(self::RESOURCES);

        $this->assertSame(0, $status, implode("\n", $lines));
        $this->assertCount(9, $lines);

        foreach (glob(self::BUNDLED . '/*.php') ?: [] as $committed) {
            $this->assertFileEquals($committed, $this->output . '/' . basename($committed));
        }
    }

    public function testWritesAPhpFileThatReturnsTheContextAsAnArray(): void
    {
        $this->writeContext('{"@context": [{"@version": 1.1, "a": {"@id": "ex:a", "@protected": true}}, null, 5, []]}');

        [$status] = $this->generate($this->resources);

        $this->assertSame(0, $status);
        $this->assertSame(
            ['@context' => [['@version' => 1.1, 'a' => ['@id' => 'ex:a', '@protected' => true]], null, 5, []]],
            require $this->output . '/sample.php',
        );
    }

    public function testCreditsTheSourceInTheGeneratedFile(): void
    {
        $this->writeContext('{"@context": {"a": "ex:a"}}');

        [$status] = $this->generate($this->resources);

        $this->assertSame(0, $status);
        $this->assertStringContainsString(
            <<<'PHP'
                 * This file includes material copied from or derived from
                 * Sample Vocabulary 1.0,
                 * https://example.com/spec.
                 * Copyright 2026 the Contributors to the Sample Vocabulary 1.0 Specification,
                 * published by the Example Group.
                 * Example License, https://example.com/license.
                 * See the NOTICE file for the terms.
                PHP,
            (string) file_get_contents($this->output . '/sample.php'),
        );
    }

    public function testEscapesQuotesAndBackslashesInStrings(): void
    {
        $this->writeContext('{"@context": {"it\'s": "back\\\\slash"}}');

        [$status] = $this->generate($this->resources);

        $this->assertSame(0, $status);
        $this->assertSame(['@context' => ["it's" => 'back\\slash']], require $this->output . '/sample.php');
    }

    #[DataProvider('refusedContexts')]
    public function testRefusesAContextThatAPhpArrayCannotHold(string $json, string $message): void
    {
        $this->writeContext($json);

        [$status, $lines] = $this->generate($this->resources);

        $this->assertSame(1, $status);
        $this->assertSame([$message], $lines);
        $this->assertFileDoesNotExist($this->output . '/sample.php');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedContexts(): iterable
    {
        yield 'empty object' => [
            '{"@context": {"a": {}}}',
            'sample/@context/a is an empty object, which a PHP array cannot hold',
        ];
        yield 'integer-like property name' => [
            '{"@context": [{"123": "ex:a"}]}',
            'sample/@context/0 has the property name "123", which PHP turns into an integer',
        ];
        yield 'malformed JSON' => ['{"@context": ', 'Syntax error'];
    }

    public function testRefusesAResourceThatDoesNotMatchItsHash(): void
    {
        $this->writeContext('{"@context": {}}', '0000');

        [$status, $lines] = $this->generate($this->resources);

        $this->assertSame(1, $status);
        $this->assertSame(['sample.jsonld does not match the SHA-256 in the manifest'], $lines);
    }

    private function writeContext(string $json, ?string $sha256 = null): void
    {
        $manifest = [
            'contexts' => [
                [
                    'name' => 'sample',
                    'url' => 'https://example.com/sample',
                    'sha256' => $sha256 ?? hash('sha256', $json),
                    'source' => [
                        'title' => 'Sample Vocabulary 1.0',
                        'url' => 'https://example.com/spec',
                        'copyright' => 'Copyright 2026 the Contributors to the Sample Vocabulary 1.0 Specification,'
                            . ' published by the Example Group',
                    ],
                    'license' => ['name' => 'Example License', 'url' => 'https://example.com/license'],
                ],
            ],
        ];

        file_put_contents($this->resources . '/sample.jsonld', $json);
        file_put_contents($this->resources . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{int, list<string>} The exit status and the lines written
     *     to standard output and standard error
     */
    private function generate(string $resources): array
    {
        $command = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::SCRIPT),
            escapeshellarg($resources),
            escapeshellarg($this->output),
        );

        exec($command, $lines, $status);

        return [$status, $lines];
    }
}
