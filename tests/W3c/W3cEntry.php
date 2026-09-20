<?php

declare(strict_types=1);

namespace SocialWeb\Test\JsonLd\W3c;

/**
 * One entry of a W3C JSON-LD API test manifest
 */
final readonly class W3cEntry
{
    /**
     * @param string $id The entry's identifier, such as `#t0001`
     * @param string $inputUrl The URL the input counts as loaded from
     * @param string $input The path of the input file
     * @param string | null $expect The path of the file that holds the
     *     expected result, for a positive entry
     * @param string | null $expectErrorCode The error code to expect, for a
     *     negative entry
     * @param array<string, mixed> $option The entry's options, as the
     *     manifest gives them
     */
    public function __construct(
        public string $id,
        public string $inputUrl,
        public string $input,
        public ?string $expect,
        public ?string $expectErrorCode,
        public array $option,
    ) {
    }
}
