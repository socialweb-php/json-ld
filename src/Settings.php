<?php

/**
 * This file is part of socialweb/json-ld
 *
 * Copyright © socialweb/json-ld Contributors
 *
 * socialweb/json-ld is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Lesser General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.
 *
 * socialweb/json-ld is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser General Public License
 * for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with socialweb/json-ld. If not, see <https://www.gnu.org/licenses/>.
 *
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace SocialWeb\JsonLd;

use JsonSerializable;
use SocialWeb\JsonLd\Context\BundledDocumentLoader;
use SocialWeb\JsonLd\Context\DocumentLoader;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use stdClass;

use function sprintf;

/**
 * The settings of a `Processor`, checked once and handed to the classes that
 * do its work
 *
 * `Processor::__construct()` takes each setting as a parameter of its own and
 * documents it; this class holds them under the same names, with the same
 * defaults.
 *
 * @internal
 */
final readonly class Settings
{
    /**
     * @var int<1, max>
     */
    public int $maxDepth;

    /**
     * @var int<1, max>
     */
    public int $maxValues;

    /**
     * @var int<1, max>
     */
    public int $maxContextOperations;

    /**
     * @param array<mixed> | string | JsonSerializable | stdClass | null $expandContext
     *
     * @throws InvalidArgument if the base is given and is not a well-formed
     *     absolute IRI, or if a limit is less than 1
     */
    public function __construct(
        public ?string $base = null,
        public array | string | JsonSerializable | stdClass | null $expandContext = null,
        public ProcessingMode $processingMode = ProcessingMode::JsonLd11,
        public ?RdfDirection $rdfDirection = null,
        public bool $strict = true,
        public DocumentLoader $documentLoader = new BundledDocumentLoader(),
        int $maxDepth = 128,
        int $maxValues = 100_000,
        int $maxContextOperations = 1_000_000,
        public bool $forbidNamedGraphs = false,
        public bool $forbidIncludedBlocks = false,
        public bool $forbidReverseProperties = false,
        public bool $requireSingleTopLevelNode = false,
    ) {
        if ($base !== null && !Grammar::isWellFormedIri($base)) {
            throw new InvalidArgument(sprintf('The base must be a well-formed absolute IRI; "%s" given', $base));
        }

        if ($maxDepth < 1) {
            throw new InvalidArgument(sprintf('maxDepth must be at least 1; %d given', $maxDepth));
        }

        if ($maxValues < 1) {
            throw new InvalidArgument(sprintf('maxValues must be at least 1; %d given', $maxValues));
        }

        if ($maxContextOperations < 1) {
            throw new InvalidArgument(
                sprintf('maxContextOperations must be at least 1; %d given', $maxContextOperations),
            );
        }

        $this->maxDepth = $maxDepth;
        $this->maxValues = $maxValues;
        $this->maxContextOperations = $maxContextOperations;
    }
}
