<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Data;

use Muon\ApiSchemaExport\Api\Data\ExportResultInterface;

/**
 * Immutable export result.
 */
class ExportResult implements ExportResultInterface
{
    /**
     * @param array<string,string> $files
     * @param string[] $moduleNames
     * @param string $baseName
     */
    public function __construct(
        private readonly array $files,
        private readonly array $moduleNames,
        private readonly string $baseName
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * @inheritDoc
     */
    public function getFileCount(): int
    {
        return count($this->files);
    }

    /**
     * @inheritDoc
     */
    public function getModuleNames(): array
    {
        return $this->moduleNames;
    }

    /**
     * @inheritDoc
     */
    public function getBaseName(): string
    {
        return $this->baseName;
    }
}
