<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Export;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Muon\ApiSchemaExport\Api\Data\ExportResultInterface;

/**
 * Writes an export result to a directory beneath the Magento root.
 *
 * Writes go through Magento's filesystem abstraction rather than raw file functions, so the
 * driver confines them beneath the Magento root and a directory argument cannot escape it.
 */
class FileWriter
{
    /**
     * Directory used when the caller names none.
     */
    public const DEFAULT_DIRECTORY = 'var/api-schema-export';

    /**
     * @param \Magento\Framework\Filesystem $filesystem
     */
    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    /**
     * Write every file in a result and return the paths written.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\ExportResultInterface $result
     * @param string $directory Relative to the Magento root.
     * @return string[] Paths relative to the Magento root, in write order.
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function write(ExportResultInterface $result, string $directory): array
    {
        $target = trim($directory) === '' ? self::DEFAULT_DIRECTORY : trim($directory);
        $target = trim(str_replace('\\', '/', $target), '/');

        $root = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $root->create($target);

        $written = [];
        foreach ($result->getFiles() as $filename => $contents) {
            $path = $target . '/' . $filename;
            $root->writeFile($path, $contents);
            $written[] = $path;
        }

        return $written;
    }
}
