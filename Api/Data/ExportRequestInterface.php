<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Api\Data;

/**
 * One export request, as supplied by a front-end.
 *
 * Carries raw user input. Resolution of defaults belongs to the export manager, not here.
 *
 * @api
 */
interface ExportRequestInterface
{
    /**
     * Get the module selectors to resolve.
     *
     * Each entry is an exact module name, a vendor namespace, or an fnmatch wildcard.
     *
     * @return string[]
     */
    public function getSelectors(): array;

    /**
     * Get the renderer codes to produce.
     *
     * @return string[]
     */
    public function getFormats(): array;

    /**
     * Get the user-supplied base filename, before sanitisation and extension.
     *
     * @return string|null Null when the manager should derive one from the selection.
     */
    public function getBaseFilename(): ?string;

    /**
     * Get the base URL override.
     *
     * @return string|null Null when the store's own base URL should be used.
     */
    public function getBaseUrl(): ?string;

    /**
     * Whether the asynchronous and bulk route variants should be included.
     *
     * @return bool
     */
    public function isIncludeAsync(): bool;

    /**
     * Whether output should be split into one file per module rather than one combined file.
     *
     * @return bool
     */
    public function isSplitByModule(): bool;

    /**
     * Get the serialization format for structured documents.
     *
     * @return string One of the RenderContextInterface FORMAT_* constants.
     */
    public function getSerialization(): string;
}
