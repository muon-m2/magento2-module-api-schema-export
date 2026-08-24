<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model;

use Magento\Framework\Exception\InputException;
use Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface;
use Muon\ApiSchemaExport\Api\Data\ExportRequestInterface;
use Muon\ApiSchemaExport\Api\Data\ExportResultInterface;
use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;
use Muon\ApiSchemaExport\Api\ExportManagerInterface;
use Muon\ApiSchemaExport\Api\RendererInterface;
use Muon\ApiSchemaExport\Api\RendererPoolInterface;
use Muon\ApiSchemaExport\Api\SurfaceResolverInterface;
use Muon\ApiSchemaExport\Model\Data\ExportResult;
use Muon\ApiSchemaExport\Model\Export\RenderContextBuilder;
use Muon\ApiSchemaExport\Model\Export\SurfaceSplitter;

/**
 * Resolves a selection and renders it into the requested formats.
 *
 * Shared verbatim by the console command and the admin controller, so both front-ends produce
 * byte-identical output for the same request.
 */
class ExportManager implements ExportManagerInterface
{
    /**
     * @param \Muon\ApiSchemaExport\Api\SurfaceResolverInterface $surfaceResolver
     * @param \Muon\ApiSchemaExport\Api\RendererPoolInterface $rendererPool
     * @param \Muon\ApiSchemaExport\Model\Export\RenderContextBuilder $contextBuilder
     * @param \Muon\ApiSchemaExport\Model\Export\SurfaceSplitter $surfaceSplitter
     * @param \Muon\ApiSchemaExport\Model\FilenameSanitizer $filenameSanitizer
     */
    public function __construct(
        private readonly SurfaceResolverInterface $surfaceResolver,
        private readonly RendererPoolInterface $rendererPool,
        private readonly RenderContextBuilder $contextBuilder,
        private readonly SurfaceSplitter $surfaceSplitter,
        private readonly FilenameSanitizer $filenameSanitizer
    ) {
    }

    /**
     * @inheritDoc
     */
    public function export(ExportRequestInterface $request): ExportResultInterface
    {
        $surface = $this->surfaceResolver->resolve($request->getSelectors());
        if (!$request->isIncludeAsync()) {
            $surface = $this->surfaceSplitter->withoutAsync($surface);
        }

        // A selector can match a real, enabled module that simply declares no webapi.xml routes.
        // Rendering that produces a structurally valid document describing nothing, which reads as
        // success. Fail with the module names instead, the same way an unmatched selector does.
        if ($surface->getOperations() === []) {
            throw new InputException(
                __(
                    'No REST routes are declared by: %1. Nothing to export.',
                    implode(', ', $surface->getModuleNames())
                )
            );
        }

        $context = $this->contextBuilder->build($request, $surface);
        $baseName = $this->filenameSanitizer->sanitizeBase(
            $request->getBaseFilename() ?? $this->deriveBaseName($surface)
        );

        $targets = $request->isSplitByModule()
            ? $this->surfaceSplitter->byModule($surface)
            : ['' => $surface];

        $files = [];
        foreach ($request->getFormats() as $code) {
            $renderer = $this->rendererPool->get((string)$code);
            foreach ($targets as $suffix => $target) {
                $rendered = $this->renderTarget($renderer, $target, $context, $baseName, (string)$suffix);
                foreach ($rendered as $filename => $contents) {
                    $files[$filename] = $contents;
                }
            }
        }

        return new ExportResult($files, $surface->getModuleNames(), $baseName);
    }

    /**
     * Render one surface with one renderer, returning its file and any companions.
     *
     * @param \Muon\ApiSchemaExport\Api\RendererInterface $renderer
     * @param \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface $surface
     * @param \Muon\ApiSchemaExport\Api\Data\RenderContextInterface $context
     * @param string $baseName
     * @param string $suffix Module name when splitting, empty otherwise.
     * @return array<string,string>
     */
    private function renderTarget(
        RendererInterface $renderer,
        ApiSurfaceInterface $surface,
        RenderContextInterface $context,
        string $baseName,
        string $suffix
    ): array {
        $stem = $suffix === ''
            ? $baseName
            : $this->filenameSanitizer->sanitizeBase($baseName . '-' . $suffix);

        $files = [
            $this->filenameSanitizer->sanitize($stem, $renderer->getFileExtension($context))
                => $renderer->render($surface, $context),
        ];

        foreach ($renderer->getCompanionFiles($surface, $context) as $companionExtension => $contents) {
            $files[$this->filenameSanitizer->sanitize($stem, (string)$companionExtension)] = $contents;
        }

        return $files;
    }

    /**
     * Derive a base filename from the resolved modules.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface $surface
     * @return string
     */
    private function deriveBaseName(ApiSurfaceInterface $surface): string
    {
        $modules = $surface->getModuleNames();
        if (count($modules) === 1) {
            return strtolower($modules[0]);
        }

        return 'api-schema';
    }
}
