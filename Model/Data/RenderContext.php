<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Data;

use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;

/**
 * Immutable per-render inputs.
 */
class RenderContext implements RenderContextInterface
{
    /**
     * @param string $baseUrl
     * @param string $title
     * @param string $format
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $title,
        private readonly string $format
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @inheritDoc
     */
    public function getFormat(): string
    {
        return $this->format;
    }
}
