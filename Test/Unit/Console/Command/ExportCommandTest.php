<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\ValidatorException;
use Muon\ApiSchemaExport\Api\Data\ExportResultInterface;
use Muon\ApiSchemaExport\Api\ExportManagerInterface;
use Muon\ApiSchemaExport\Api\RendererPoolInterface;
use Muon\ApiSchemaExport\Console\Command\ExportCommand;
use Muon\ApiSchemaExport\Console\Command\ExportInputResolver;
use Muon\ApiSchemaExport\Model\Export\FileWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @see \Muon\ApiSchemaExport\Console\Command\ExportCommand
 */
class ExportCommandTest extends TestCase
{
    /**
     * @var MockObject&ExportManagerInterface
     */
    private MockObject $exportManager;

    /**
     * @var MockObject&FileWriter
     */
    private MockObject $fileWriter;

    /**
     * @var Stub&RendererPoolInterface
     */
    private Stub $rendererPool;

    /**
     * @var ExportCommand
     */
    private ExportCommand $command;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->exportManager = $this->createMock(ExportManagerInterface::class);
        $this->fileWriter = $this->createMock(FileWriter::class);
        $this->rendererPool = $this->createStub(RendererPoolInterface::class);
        $this->rendererPool->method('getCodes')->willReturn(['http', 'openapi', 'postman', 'swagger']);

        // The resolver is exercised for real: its interactive/non-interactive behaviour is the
        // thing these tests exist to pin, so doubling it would test nothing.
        $resolver = new ExportInputResolver($this->rendererPool, new QuestionHelper());

        $this->command = new ExportCommand($this->exportManager, $this->fileWriter, $resolver);
    }

    /**
     * A fully specified run writes the rendered files and reports success.
     */
    public function testFullySpecifiedRunWritesFilesAndSucceeds(): void
    {
        $result = $this->createStub(ExportResultInterface::class);
        $result->method('getFiles')->willReturn(['muon.openapi.json' => '{}']);
        $result->method('getFileCount')->willReturn(1);
        $result->method('getModuleNames')->willReturn(['Muon_FileAttachment']);

        $this->exportManager->expects(self::once())->method('export')->willReturn($result);
        $this->fileWriter->expects(self::once())
            ->method('write')
            ->willReturn(['var/api-schema-export/muon.openapi.json']);

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['modules' => ['Muon'], '--format' => ['openapi']]);

        self::assertSame(Cli::RETURN_SUCCESS, $exitCode);
        self::assertStringContainsString('muon.openapi.json', $tester->getDisplay());
    }

    /**
     * A non-interactive run with no selector must fail fast rather than block on a prompt.
     *
     * A command that prompts when stdin is not a terminal hangs a CI job until it is killed, so
     * this is the single most important behaviour of the whole command.
     */
    public function testNonInteractiveRunWithoutSelectorsFailsInsteadOfPrompting(): void
    {
        $this->exportManager->expects(self::never())->method('export');
        $this->fileWriter->expects(self::never())->method('write');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(
            ['--format' => ['openapi']],
            ['interactive' => false]
        );

        self::assertSame(Cli::RETURN_FAILURE, $exitCode);
        self::assertStringContainsString('module selector', $tester->getDisplay());
    }

    /**
     * An unmatched selector exits with the dedicated no-match code.
     */
    public function testUnmatchedSelectorExitsWithNoMatchCode(): void
    {
        $this->exportManager->expects(self::once())
            ->method('export')
            ->willThrowException(new NoSuchEntityException(__('No enabled module matches: Nope_Nope')));
        $this->fileWriter->expects(self::never())->method('write');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(
            ['modules' => ['Nope_Nope'], '--format' => ['openapi']],
            ['interactive' => false]
        );

        self::assertSame(ExportCommand::RETURN_NO_MATCH, $exitCode);
        self::assertStringContainsString('Nope_Nope', $tester->getDisplay());
    }

    /**
     * Writing several formats to stdout would interleave them, so the combination is rejected.
     */
    public function testStdoutWithMultipleFormatsIsRejected(): void
    {
        $this->exportManager->expects(self::never())->method('export');
        $this->fileWriter->expects(self::never())->method('write');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(
            ['modules' => ['Muon'], '--format' => ['openapi', 'http'], '--stdout' => true],
            ['interactive' => false]
        );

        self::assertSame(Cli::RETURN_FAILURE, $exitCode);
        self::assertStringContainsString('stdout', $tester->getDisplay());
    }

    /**
     * An unknown format is rejected before any work is done.
     */
    public function testUnknownFormatIsRejected(): void
    {
        $this->exportManager->expects(self::never())->method('export');
        $this->fileWriter->expects(self::never())->method('write');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(
            ['modules' => ['Muon'], '--format' => ['yaml-ish']],
            ['interactive' => false]
        );

        self::assertSame(Cli::RETURN_FAILURE, $exitCode);
        self::assertStringContainsString('yaml-ish', $tester->getDisplay());
    }

    /**
     * The --stdout flag writes the rendered document without touching the filesystem.
     */
    public function testStdoutWritesDocumentWithoutWritingFiles(): void
    {
        $result = $this->createStub(ExportResultInterface::class);
        $result->method('getFiles')->willReturn(['muon.openapi.json' => '{"openapi":"3.1.0"}']);
        $result->method('getFileCount')->willReturn(1);
        $result->method('getModuleNames')->willReturn(['Muon_FileAttachment']);

        $this->exportManager->expects(self::once())->method('export')->willReturn($result);
        $this->fileWriter->expects(self::never())->method('write');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(
            ['modules' => ['Muon'], '--format' => ['openapi'], '--stdout' => true],
            ['interactive' => false]
        );

        self::assertSame(Cli::RETURN_SUCCESS, $exitCode);
        self::assertStringContainsString('"openapi":"3.1.0"', $tester->getDisplay());
    }

    /**
     * A single format can still produce several files, and --stdout must reject that.
     *
     * The postman and http renderers each emit a companion environment file, so counting
     * --format values alone is not enough to know how many files a request produces.
     */
    public function testStdoutRejectsAMultiFileResultFromASingleFormat(): void
    {
        $result = $this->createStub(ExportResultInterface::class);
        $result->method('getFiles')->willReturn([
            'muon.postman_collection.json' => '{}',
            'muon.postman_environment.json' => '{}',
        ]);
        $result->method('getFileCount')->willReturn(2);
        $result->method('getModuleNames')->willReturn(['Muon_FileAttachment']);

        $this->exportManager->expects(self::once())->method('export')->willReturn($result);
        $this->fileWriter->expects(self::never())->method('write');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(
            ['modules' => ['Muon'], '--format' => ['postman'], '--stdout' => true],
            ['interactive' => false]
        );

        self::assertSame(Cli::RETURN_FAILURE, $exitCode);
        self::assertStringContainsString('muon.postman_environment.json', $tester->getDisplay());
    }

    /**
     * --stdout combined with --split is rejected before any work is done.
     */
    public function testStdoutWithSplitIsRejected(): void
    {
        $this->exportManager->expects(self::never())->method('export');
        $this->fileWriter->expects(self::never())->method('write');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(
            ['modules' => ['Muon'], '--format' => ['openapi'], '--stdout' => true, '--split' => true],
            ['interactive' => false]
        );

        self::assertSame(Cli::RETURN_FAILURE, $exitCode);
        self::assertStringContainsString('--split', $tester->getDisplay());
    }

    /**
     * An unwritable output directory is reported as an input error, not a stack trace.
     */
    public function testUnwritableOutputDirectoryIsReportedCleanly(): void
    {
        $result = $this->createStub(ExportResultInterface::class);
        $result->method('getFiles')->willReturn(['muon.openapi.json' => '{}']);
        $result->method('getFileCount')->willReturn(1);
        $result->method('getModuleNames')->willReturn(['Muon_FileAttachment']);

        $this->exportManager->expects(self::once())->method('export')->willReturn($result);
        $this->fileWriter->expects(self::once())
            ->method('write')
            ->willThrowException(new ValidatorException(__('Path is outside of the allowed directory.')));

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(
            ['modules' => ['Muon'], '--format' => ['openapi'], '--output' => '../../etc'],
            ['interactive' => false]
        );

        self::assertSame(Cli::RETURN_FAILURE, $exitCode);
        self::assertStringContainsString('output directory', $tester->getDisplay());
    }
}
