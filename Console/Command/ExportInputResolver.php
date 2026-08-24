<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Console\Command;

use Magento\Framework\Exception\InputException;
use Muon\ApiSchemaExport\Api\Data\ExportRequestInterface;
use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;
use Muon\ApiSchemaExport\Api\RendererPoolInterface;
use Muon\ApiSchemaExport\Model\Data\ExportRequest;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Turns console input into an export request, prompting only when the terminal is interactive.
 *
 * The interactive/non-interactive split is the important behaviour here. Prompting into a closed
 * stdin hangs a CI job until something kills it, so a missing value raises InputException instead
 * whenever the input is not interactive.
 */
class ExportInputResolver
{
    /**
     * Format value meaning "every registered renderer".
     */
    private const FORMAT_ALL = 'all';

    /**
     * @param \Muon\ApiSchemaExport\Api\RendererPoolInterface $rendererPool
     * @param \Symfony\Component\Console\Helper\QuestionHelper $questionHelper
     */
    public function __construct(
        private readonly RendererPoolInterface $rendererPool,
        private readonly QuestionHelper $questionHelper
    ) {
    }

    /**
     * Resolve module selectors.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string[] $supplied
     * @return string[]
     * @throws \Magento\Framework\Exception\InputException When none supplied and input is not interactive.
     */
    public function resolveSelectors(InputInterface $input, OutputInterface $output, array $supplied): array
    {
        $selectors = array_values(array_filter($supplied));
        if ($selectors !== []) {
            return $selectors;
        }

        if (!$input->isInteractive()) {
            throw new InputException(
                __('At least one module selector is required. Pass it as an argument, e.g. "Muon".')
            );
        }

        $answer = (string)$this->questionHelper->ask(
            $input,
            $output,
            new Question('Module selector (exact, namespace, or wildcard) [*]: ', '*')
        );

        return array_values(array_filter(array_map('trim', explode(',', $answer))));
    }

    /**
     * Resolve output formats.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string[] $supplied
     * @return string[]
     * @throws \Magento\Framework\Exception\InputException When a format is unknown, or none supplied
     *         and input is not interactive.
     */
    public function resolveFormats(InputInterface $input, OutputInterface $output, array $supplied): array
    {
        $available = $this->rendererPool->getCodes();
        $formats = array_values(array_filter($supplied));

        if ($formats === [] && $input->isInteractive()) {
            $question = new ChoiceQuestion('Output format(s), comma-separated: ', $available, 0);
            $question->setMultiselect(true);
            $formats = (array)$this->questionHelper->ask($input, $output, $question);
        }

        if ($formats === []) {
            throw new InputException(
                __('At least one --format is required. Available: %1.', implode(', ', $available))
            );
        }

        if (in_array(self::FORMAT_ALL, $formats, true)) {
            return $available;
        }

        $unknown = array_diff($formats, $available);
        if ($unknown !== []) {
            throw new InputException(
                __(
                    'Unknown format(s): %1. Available: %2.',
                    implode(', ', $unknown),
                    implode(', ', $available)
                )
            );
        }

        return array_values($formats);
    }

    /**
     * Build the export request from resolved values.
     *
     * @param string[] $selectors
     * @param string[] $formats
     * @param bool $useYaml
     * @param string|null $filename
     * @param string|null $baseUrl
     * @param bool $includeAsync
     * @param bool $splitByModule
     * @return \Muon\ApiSchemaExport\Api\Data\ExportRequestInterface
     */
    public function buildRequest(
        array $selectors,
        array $formats,
        bool $useYaml,
        ?string $filename,
        ?string $baseUrl,
        bool $includeAsync,
        bool $splitByModule
    ): ExportRequestInterface {
        return new ExportRequest(
            $selectors,
            $formats,
            $useYaml ? RenderContextInterface::FORMAT_YAML : RenderContextInterface::FORMAT_JSON,
            $filename,
            $baseUrl,
            $includeAsync,
            $splitByModule
        );
    }
}
