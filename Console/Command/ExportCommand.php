<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\ValidatorException;
use Muon\ApiSchemaExport\Api\ExportManagerInterface;
use Muon\ApiSchemaExport\Model\Export\FileWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates API description files for a selection of modules.
 *
 * Every value can be supplied as an argument or option. When one is missing and the terminal is
 * interactive, the command asks. When it is not interactive it fails with a message naming what
 * was missing, because a command that prompts into a closed stdin hangs a CI job indefinitely.
 */
class ExportCommand extends Command
{
    /**
     * Exit code for a selector that matched no enabled module.
     *
     * Distinct from a general failure so a caller can tell "you asked for something that is not
     * here" apart from "something went wrong".
     */
    public const RETURN_NO_MATCH = 2;

    /**
     * Console command name.
     */
    private const NAME = 'muon:api-schema:export';

    private const ARGUMENT_MODULES = 'modules';
    private const OPTION_FORMAT = 'format';
    private const OPTION_OUTPUT = 'output';
    private const OPTION_FILENAME = 'filename';
    private const OPTION_BASE_URL = 'base-url';
    private const OPTION_YAML = 'yaml';
    private const OPTION_SPLIT = 'split';
    private const OPTION_NO_ASYNC = 'no-async';
    private const OPTION_STDOUT = 'stdout';

    /**
     * @param \Muon\ApiSchemaExport\Api\ExportManagerInterface $exportManager
     * @param \Muon\ApiSchemaExport\Model\Export\FileWriter $fileWriter
     * @param \Muon\ApiSchemaExport\Console\Command\ExportInputResolver $inputResolver
     * @param string|null $name
     */
    public function __construct(
        private readonly ExportManagerInterface $exportManager,
        private readonly FileWriter $fileWriter,
        private readonly ExportInputResolver $inputResolver,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription(
                'Generate .http, Postman, OpenAPI 3.1 and Swagger 2.0 files for a set of modules.'
            )
            ->addArgument(
                self::ARGUMENT_MODULES,
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Module selectors: exact (Muon_FileAttachment), namespace (Muon), '
                . 'wildcard (Magento_Catalog*) or * for everything.'
            )
            ->addOption(
                self::OPTION_FORMAT,
                'f',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Output format, repeatable. Use "all" for every format.'
            )
            ->addOption(
                self::OPTION_OUTPUT,
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory relative to the Magento root.',
                FileWriter::DEFAULT_DIRECTORY
            )
            ->addOption(
                self::OPTION_FILENAME,
                null,
                InputOption::VALUE_REQUIRED,
                'Base filename, without extension. Derived from the selection when omitted.'
            )
            ->addOption(
                self::OPTION_BASE_URL,
                null,
                InputOption::VALUE_REQUIRED,
                'Base URL for generated requests. Defaults to the default store view.'
            )
            ->addOption(self::OPTION_YAML, null, InputOption::VALUE_NONE, 'Emit YAML instead of JSON.')
            ->addOption(self::OPTION_SPLIT, null, InputOption::VALUE_NONE, 'One file per module.')
            ->addOption(
                self::OPTION_NO_ASYNC,
                null,
                InputOption::VALUE_NONE,
                'Omit the /async and /async/bulk route variants.'
            )
            ->addOption(
                self::OPTION_STDOUT,
                null,
                InputOption::VALUE_NONE,
                'Write to stdout instead of disk. Only valid when one file is produced.'
            );

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $selectors = $this->inputResolver->resolveSelectors(
                $input,
                $output,
                (array)$input->getArgument(self::ARGUMENT_MODULES)
            );
            $formats = $this->inputResolver->resolveFormats(
                $input,
                $output,
                (array)$input->getOption(self::OPTION_FORMAT)
            );
        } catch (InputException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $toStdout = (bool)$input->getOption(self::OPTION_STDOUT);
        if ($toStdout && (count($formats) > 1 || $input->getOption(self::OPTION_SPLIT))) {
            $output->writeln(
                '<error>--stdout writes a single document, so it cannot be combined with '
                . 'more than one --format or with --split.</error>'
            );
            return Cli::RETURN_FAILURE;
        }

        return $this->runExport($input, $output, $selectors, $formats, $toStdout);
    }

    /**
     * Resolve, render and deliver the export.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string[] $selectors
     * @param string[] $formats
     * @param bool $toStdout
     * @return int
     */
    private function runExport(
        InputInterface $input,
        OutputInterface $output,
        array $selectors,
        array $formats,
        bool $toStdout
    ): int {
        $request = $this->inputResolver->buildRequest(
            $selectors,
            $formats,
            (bool)$input->getOption(self::OPTION_YAML),
            $this->stringOption($input, self::OPTION_FILENAME),
            $this->stringOption($input, self::OPTION_BASE_URL),
            !$input->getOption(self::OPTION_NO_ASYNC),
            (bool)$input->getOption(self::OPTION_SPLIT)
        );

        try {
            $result = $this->exportManager->export($request);
        } catch (NoSuchEntityException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::RETURN_NO_MATCH;
        } catch (InputException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        if ($toStdout) {
            // Some renderers emit a companion environment file alongside their document, so even
            // a single format can produce two files. Concatenating them to stdout would hand the
            // caller an unusable stream, which is the very thing --stdout must not do.
            if ($result->getFileCount() > 1) {
                $output->writeln(
                    sprintf(
                        '<error>--stdout writes a single document, but this request produces %d '
                        . 'files (%s). Drop --stdout and use --output instead.</error>',
                        $result->getFileCount(),
                        implode(', ', array_keys($result->getFiles()))
                    )
                );
                return Cli::RETURN_FAILURE;
            }

            foreach ($result->getFiles() as $contents) {
                $output->writeln($contents);
            }
            return Cli::RETURN_SUCCESS;
        }

        try {
            $written = $this->fileWriter->write($result, (string)$input->getOption(self::OPTION_OUTPUT));
        } catch (ValidatorException | FileSystemException $exception) {
            // A --output outside the Magento root is rejected by the filesystem driver. Report it
            // as an input error rather than letting a stack trace reach the terminal.
            $output->writeln('<error>Could not write to the output directory: '
                . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
        $output->writeln(
            sprintf(
                '<info>Wrote %d file(s) for %s:</info>',
                $result->getFileCount(),
                implode(', ', $result->getModuleNames())
            )
        );
        foreach ($written as $path) {
            $output->writeln('  ' . $path);
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * Read an option as a trimmed string, or null when it is absent or blank.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param string $name
     * @return string|null
     */
    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string)$input->getOption($name));

        return $value === '' ? null : $value;
    }
}
