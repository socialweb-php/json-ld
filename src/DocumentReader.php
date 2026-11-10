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

use JsonException;
use JsonSerializable;
use SocialWeb\JsonLd\Exception\InvalidArgument;
use SocialWeb\JsonLd\Exception\JsonLdError;
use SocialWeb\JsonLd\Exception\JsonLdErrorCode;
use SocialWeb\JsonLd\Exception\LimitExceeded;
use SplObjectStorage;
use stdClass;

use function array_is_list;
use function array_pop;
use function array_reverse;
use function get_debug_type;
use function get_object_vars;
use function is_array;
use function is_finite;
use function is_float;
use function is_scalar;
use function is_string;
use function json_decode;
use function preg_match;
use function sprintf;
use function str_starts_with;

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
 * array, and any other PHP array is a JSON object. An object is a JSON object
 * only when its class is `stdClass` itself, not a subclass. The reader always
 * builds a new tree, so the caller's document is never shared with or changed
 * by the processor.
 *
 * A value of a PHP type that is not a JSON type, such as an object of another
 * class, a closure, or a resource, is the caller's mistake. When the reader
 * would read it within the limits on depth and on the number of values, it is
 * refused as `InvalidArgument` before anything is read. Beyond them, it may be
 * refused as `InvalidArgument` too, or the document may be refused as
 * `LimitExceeded`. A value of a JSON type whose content JSON text cannot
 * express is a malformed document and is refused as `JsonLdError` with the code
 * `loading document failed`.
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

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Returns the document in the internal form
     *
     * A string is decoded as JSON. An array or `stdClass` tree is taken as an
     * already-decoded document. A `JsonSerializable` is read as what its
     * `jsonSerialize()` returns: a string as JSON text, and anything else as
     * an already-decoded document. Reading counts every value and measures
     * the nesting depth, rejecting a non-finite number or a string that is not
     * valid UTF-8, which JSON cannot represent.
     *
     * @param array<mixed> | string | JsonSerializable | stdClass $document A
     *     JSON-encoded string, a decoded document, a document built by hand,
     *     or an object that serializes to one of them
     *
     * @return list<mixed> | stdClass | string | int | float | bool | null
     *
     * @throws InvalidArgument if a value has a PHP type that is not a JSON
     *     type: an object of a class other than `stdClass`, a `stdClass`
     *     subclass among them, a closure, or a resource. This includes a
     *     `JsonSerializable` inside the document, or one that another's
     *     `jsonSerialize()` returns. It is raised before anything is read, for
     *     every such value the reader would read within the limits on depth
     *     and on the number of values, and may be raised for one beyond them.
     * @throws JsonLdError with the code `loading document failed` if the
     *     string is not valid JSON, or if a number is not finite, or if a
     *     string or a property name is not valid UTF-8, or if a property name
     *     starts with a NUL byte
     * @throws LimitExceeded if the document exceeds a limit
     */
    public function read(
        array | string | JsonSerializable | stdClass $document,
    ): array | stdClass | string | int | float | bool | null {
        if ($document instanceof JsonSerializable) {
            /** @var mixed $document */
            $document = $document->jsonSerialize();
        }

        if (is_string($document)) {
            $document = $this->decode($document);
        } else {
            $this->checkTypes($document);
        }

        $this->values = 0;

        return $this->walk($document, 0);
    }

    /**
     * Checks that every value in a decoded document has a PHP type JSON can
     * hold, before any of it is read, so that a caller's mistake is reported
     * as one and not as a fault of the document
     *
     * The JSON types are `null`, booleans, integers, floats, strings, PHP
     * arrays, and objects whose class is `stdClass` itself. Values are visited
     * in the order `walk()` reads them, a container before its entries and
     * the entries first to last. The check stops after `maxValues` values and
     * does not look inside nesting deeper than `maxDepth`. An object is walked
     * again only when it is met at a shallower depth than before, since that
     * walk reaches everything a deeper one would, so an object that holds
     * itself is walked once. An object met again is otherwise skipped, and its
     * content is not counted again, where `walk()` reads and counts it each
     * time, so the check may look further than `walk()` would read. Every
     * value that `walk()` would read within the limits is checked, and a value
     * beyond them may or may not be; `walk()` refuses a document that goes
     * beyond either limit.
     *
     * @throws InvalidArgument naming the type of the first value found whose
     *     PHP type is not a JSON type
     */
    private function checkTypes(mixed $document): void
    {
        $pending = [[$document, 1]];
        $visited = 0;
        /** @var SplObjectStorage<stdClass, int> $walked */
        $walked = new SplObjectStorage();

        while (($next = array_pop($pending)) !== null) {
            if (++$visited > $this->settings->maxValues) {
                return;
            }

            [$current, $depth] = $next;

            if (is_array($current) || ($current instanceof stdClass && $current::class === stdClass::class)) {
                if ($depth > $this->settings->maxDepth) {
                    continue;
                }

                if ($current instanceof stdClass) {
                    if (isset($walked[$current]) && $walked[$current] <= $depth) {
                        continue;
                    }

                    $walked[$current] = $depth;
                }

                // Pushed in reverse, the entries are taken off the stack first
                // to last, in the order walk() reads them.
                /** @var mixed $entry */
                foreach (array_reverse(is_array($current) ? $current : get_object_vars($current)) as $entry) {
                    $pending[] = [$entry, $depth + 1];
                }
            } elseif ($current !== null && !is_scalar($current)) {
                throw new InvalidArgument(
                    sprintf('A document may hold only JSON values; %s given', get_debug_type($current)),
                );
            }
        }
    }

    private function decode(string $json): mixed
    {
        try {
            // json_decode() counts one level more than maxDepth does: a bare
            // scalar is depth 1 to it and depth 0 here. Its argument cannot
            // exceed 2^31 - 1, so a larger limit is capped, and walk() enforces
            // it.
            $depth = $this->settings->maxDepth < self::DECODER_MAX_DEPTH
                ? $this->settings->maxDepth + 1
                : self::DECODER_MAX_DEPTH;

            return json_decode($json, false, $depth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            if ($exception->getCode() === JSON_ERROR_DEPTH) {
                throw new LimitExceeded('maxDepth', $this->settings->maxDepth, $exception);
            }

            throw new JsonLdError(
                JsonLdErrorCode::LoadingDocumentFailed,
                sprintf('the text is not valid JSON (%s)', $exception->getMessage()),
                $exception,
            );
        }
    }

    /**
     * @param mixed $value A value of a JSON type, which `json_decode()`
     *     returns and `checkTypes()` has checked for
     * @param int $depth The number of containers enclosing the value
     *
     * @return list<mixed> | stdClass | string | int | float | bool | null
     */
    private function walk(mixed $value, int $depth): array | stdClass | string | int | float | bool | null
    {
        if (++$this->values > $this->settings->maxValues) {
            throw new LimitExceeded('maxValues', $this->settings->maxValues);
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

        if (is_string($value) && preg_match('//u', $value) !== 1) {
            throw new JsonLdError(JsonLdErrorCode::LoadingDocumentFailed, 'a string is not valid UTF-8');
        }

        if (is_float($value) && !is_finite($value)) {
            throw new JsonLdError(JsonLdErrorCode::LoadingDocumentFailed, 'a number is not finite');
        }

        /** @var bool | float | int | string | null $value */
        return $value;
    }

    /**
     * @param array<mixed> $entries
     */
    private function walkObject(array $entries, int $depth): stdClass
    {
        $this->checkDepth($depth);
        $object = new stdClass();

        foreach ($entries as $key => $entry) {
            if (is_string($key) && str_starts_with($key, "\0")) {
                throw new JsonLdError(
                    JsonLdErrorCode::LoadingDocumentFailed,
                    'a property name starts with a NUL byte',
                );
            }

            if (is_string($key) && preg_match('//u', $key) !== 1) {
                throw new JsonLdError(
                    JsonLdErrorCode::LoadingDocumentFailed,
                    'a property name is not valid UTF-8',
                );
            }

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
        if ($depth > $this->settings->maxDepth) {
            throw new LimitExceeded('maxDepth', $this->settings->maxDepth);
        }
    }
}
