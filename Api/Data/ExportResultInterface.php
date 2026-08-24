<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Api\Data;

/**
 * The generated files for one export request.
 *
 * Files are returned as content rather than paths so callers stay free to stream them, archive
 * them, or write them wherever they choose.
 *
 * @api
 */
interface ExportResultInterface
{
    /**
     * Get the generated files, keyed by sanitised filename.
     *
     * @return array<string,string>
     */
    public function getFiles(): array;

    /**
     * Get the number of generated files.
     *
     * @return int
     */
    public function getFileCount(): int;

    /**
     * Get the module names covered by this export.
     *
     * @return string[]
     */
    public function getModuleNames(): array;

    /**
     * Get the sanitised base filename the export resolved to, without any extension.
     *
     * Callers that package the files differently — an archive, say — name the package from this
     * so a multi-format download is not named differently from a single-format one.
     *
     * @return string
     */
    public function getBaseName(): string;
}
