<?php

/**
 * This file is part of socialweb/json-ld
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

use JsonException;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\LimitExceeded;
use SocialWeb\JsonLd\Exception\MalformedJson;
use stdClass;

use function array_is_list;
use function get_debug_type;
use function get_object_vars;
use function is_array;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function preg_match;
use function sprintf;

use const JSON_ERROR_DEPTH;
use const JSON_THROW_ON_ERROR;

/**
 * Reads a document into the library's internal form and enforces the limits
 *
 * The internal form is what `json_decode()` produces by default: JSON
 * objects as `stdClass`, JSON arrays as PHP lists, and scalars as PHP
 * scalars. This form distinguishes an empty object from an empty array, which
 * the JSON-LD algorithms need. Associative arrays are accepted under one rule:
 * a PHP array that is a list, including an empty array, is treated as a JSON
 * array, and any other PHP array is a JSON object. The reader always builds a
 * new tree, so the caller's document is never shared with or changed by
 * the processor.
 *
 * @internal
 */
final class DocumentReader
{
    /**
     * The largest depth `json_decode()` accepts, 2^31 - 1
     */
    private const int DECODER_MAX_DEPTH = 2_147_483_647;

    private int $values = 0;

    public function __construct(private readonly Limits $limits)
    {
    }

    /**
     * Returns the document in the internal form
     *
     * A string is decoded as JSON. An array or `stdClass` tree is taken as an
     * already-decoded document. Reading counts every value and measures the
     * nesting depth, rejecting a non-finite number or a string that is not
     * valid UTF-8, which JSON cannot represent.
     *
     * @param string | array<mixed> | object $document A JSON-encoded string, a
     *     decoded document, or a document built by hand
     *
     * @return array<int, mixed> | object | string | int | float | bool | null
     *
     * @throws MalformedJson if the string is not a valid JSON-encoded string,
     *     or if a value cannot be represented in JSON
     * @throws LimitExceeded if the document exceeds a limit
     * @throws InvalidArgument if the object is not a `stdClass`
     */
    public function read(string | array | object $document): array | object | string | int | float | bool | null
    {
        if (is_string($document)) {
            $document = $this->decode($document);
        }

        $this->values = 0;

        return $this->walk($document, 0);
    }

    private function decode(string $json): mixed
    {
        try {
            // json_decode() counts one level more than maxDepth does: a bare
            // scalar is depth 1 to it and depth 0 here. Its argument cannot
            // exceed 2^31 - 1, so a larger limit is capped, and walk() enforces it.
            $depth = $this->limits->maxDepth < self::DECODER_MAX_DEPTH
                ? $this->limits->maxDepth + 1
                : self::DECODER_MAX_DEPTH;

            return json_decode($json, false, $depth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            if ($exception->getCode() === JSON_ERROR_DEPTH) {
                throw new LimitExceeded('maxDepth', $this->limits->maxDepth, $exception);
            }

            throw new MalformedJson($exception->getCode(), $exception->getMessage(), $exception);
        }
    }

    /**
     * @param int $depth The number of containers enclosing the value
     *
     * @return array<int, mixed> | object | string | int | float | bool | null
     */
    private function walk(mixed $value, int $depth): array | object | string | int | float | bool | null
    {
        if (++$this->values > $this->limits->maxValues) {
            throw new LimitExceeded('maxValues', $this->limits->maxValues);
        }

        if ($value instanceof stdClass) {
            return $this->walkObject(get_object_vars($value), $depth + 1);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return $this->walkList($value, $depth + 1);
            }

            return $this->walkObject($value, $depth + 1);
        }

        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new MalformedJson(0, 'a string is not valid UTF-8');
            }

            return $value;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new MalformedJson(0, 'a number is not finite');
            }

            return $value;
        }

        if ($value === null || $value === true || $value === false || is_int($value)) {
            return $value;
        }

        throw new InvalidArgument(sprintf('A document may hold only JSON values; %s given', get_debug_type($value)));
    }

    /**
     * @param array<mixed> $entries
     */
    private function walkObject(array $entries, int $depth): stdClass
    {
        $this->checkDepth($depth);
        $object = new stdClass();

        foreach ($entries as $key => $entry) {
            $object->{$key} = $this->walk($entry, $depth);
        }

        return $object;
    }

    /**
     * @param list<mixed> $entries
     *
     * @return list<mixed>
     */
    private function walkList(array $entries, int $depth): array
    {
        $this->checkDepth($depth);
        $list = [];

        foreach ($entries as $entry) {
            $list[] = $this->walk($entry, $depth);
        }

        return $list;
    }

    private function checkDepth(int $depth): void
    {
        if ($depth > $this->limits->maxDepth) {
            throw new LimitExceeded('maxDepth', $this->limits->maxDepth);
        }
    }
}
