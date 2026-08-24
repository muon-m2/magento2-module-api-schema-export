<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Api;

use Magento\Framework\Phrase;
use Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface;
use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;

/**
 * Renders a resolved API surface into one output format.
 *
 * Implementations are registered in the renderer pool's di.xml argument, which is this module's
 * primary extension point: a new output format is one class plus one di.xml node.
 *
 * Implementations must never embed a credential in their output. Generated artifacts are meant to
 * be shared, so authentication belongs in placeholder variables and companion environment files
 * carrying empty values.
 *
 * @api
 */
interface RendererInterface
{
    /**
     * Get the stable code this renderer is selected by.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Get the human-readable label shown in a front-end.
     *
     * @return \Magento\Framework\Phrase
     */
    public function getLabel(): Phrase;

    /**
     * Get the filename extension, without a leading dot.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\RenderContextInterface $context
     * @return string
     */
    public function getFileExtension(RenderContextInterface $context): string;

    /**
     * Get the MIME type for a download of this renderer's output.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\RenderContextInterface $context
     * @return string
     */
    public function getContentType(RenderContextInterface $context): string;

    /**
     * Render the surface.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface $surface
     * @param \Muon\ApiSchemaExport\Api\Data\RenderContextInterface $context
     * @return string
     */
    public function render(ApiSurfaceInterface $surface, RenderContextInterface $context): string;

    /**
     * Get companion files this renderer emits alongside its main output.
     *
     * Used for environment files, which must always carry empty values.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface $surface
     * @param \Muon\ApiSchemaExport\Api\Data\RenderContextInterface $context
     * @return array<string,string> Filename suffix => file contents.
     */
    public function getCompanionFiles(
        ApiSurfaceInterface $surface,
        RenderContextInterface $context
    ): array;
}
