<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model\Export;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Muon\ApiSchemaExport\Api\Data\ExportResultInterface;
use Muon\ApiSchemaExport\Model\Export\FileWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @see \Muon\ApiSchemaExport\Model\Export\FileWriter
 */
class FileWriterTest extends TestCase
{
    /**
     * @var MockObject&WriteInterface
     */
    /**
     * @var MockObject
     */
    private MockObject $directory;

    /**
     * @var FileWriter
     */
    private FileWriter $fileWriter;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->directory = $this->createMock(WriteInterface::class);

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->directory);

        $this->fileWriter = new FileWriter($filesystem);
    }

    /**
     * Each file is written under the target directory and its path returned.
     */
    public function testEachFileIsWrittenAndItsPathReturned(): void
    {
        $this->directory->expects(self::once())->method('create')->with('var/api-schema-export');
        $this->directory->expects(self::exactly(2))->method('writeFile');

        $written = $this->fileWriter->write(
            $this->makeResult(['a.json' => '{}', 'b.http' => 'GET /']),
            'var/api-schema-export'
        );

        self::assertSame(
            ['var/api-schema-export/a.json', 'var/api-schema-export/b.http'],
            $written
        );
    }

    /**
     * A blank directory falls back to the module's default.
     */
    public function testBlankDirectoryFallsBackToTheDefault(): void
    {
        $this->directory->expects(self::once())->method('create')->with(FileWriter::DEFAULT_DIRECTORY);
        $this->directory->method('writeFile');

        $written = $this->fileWriter->write($this->makeResult(['a.json' => '{}']), '   ');

        self::assertSame([FileWriter::DEFAULT_DIRECTORY . '/a.json'], $written);
    }

    /**
     * Separators are normalised and the path is anchored, not absolute.
     */
    public function testDirectoryIsNormalisedAndAnchored(): void
    {
        $this->directory->expects(self::once())->method('create')->with('var/custom/dir');
        $this->directory->method('writeFile');

        $written = $this->fileWriter->write($this->makeResult(['a.json' => '{}']), '/var\\custom/dir/');

        self::assertSame(['var/custom/dir/a.json'], $written);
    }

    /**
     * Writes go through Magento's filesystem abstraction, whose driver confines them to the root.
     */
    public function testWritesUseTheMagentoRootDirectory(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())
            ->method('getDirectoryWrite')
            ->with(DirectoryList::ROOT)
            ->willReturn($this->directory);
        $this->directory->expects(self::once())->method('writeFile');

        (new FileWriter($filesystem))->write($this->makeResult(['a.json' => '{}']), 'var/x');
    }

    /**
     * @param array<string,string> $files
     * @return \Muon\ApiSchemaExport\Api\Data\ExportResultInterface
     */
    private function makeResult(array $files): ExportResultInterface
    {
        $result = $this->createStub(ExportResultInterface::class);
        $result->method('getFiles')->willReturn($files);

        return $result;
    }
}
