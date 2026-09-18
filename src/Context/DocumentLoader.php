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

namespace SocialWeb\JsonLd\Context;

use SocialWeb\JsonLd\Exception\JsonLdError;

/**
 * Supplies the context documents that a JSON-LD document references by URL
 *
 * The processor never fetches anything itself. Every context URL it meets
 * goes to the loader it was given, so the loader decides which contexts
 * exist and where they come from.
 */
interface DocumentLoader
{
    /**
     * Returns the context document for the URL
     *
     * @param string $url An absolute URL, exactly as the processor resolved it
     *
     * @throws JsonLdError with the code `loading remote context failed` if the
     *     loader cannot supply the document
     */
    public function load(string $url): LoadedDocument;
}
