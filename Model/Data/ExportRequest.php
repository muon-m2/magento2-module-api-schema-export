<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Data;

use Muon\ApiSchemaExport\Api\Data\ExportRequestInterface;

/**
 * Immutable export request.
 */
class ExportRequest implements ExportRequestInterface
{
    /**
     * No parameter carries a default. Both front-ends resolve every value explicitly, and a
     * silent default here would let one of them drift from the other.
     *
     * @param string[] $selectors
     * @param string[] $formats
     * @param string $serialization One of the RenderContextInterface FORMAT_* constants.
     * @param string|null $baseFilename
     * @param string|null $baseUrl
     * @param bool $includeAsync
     * @param bool $splitByModule
     */
    public function __construct(
        private readonly array $selectors,
        private readonly array $formats,
        private readonly string $serialization,
        private readonly ?string $baseFilename,
        private readonly ?string $baseUrl,
        private readonly bool $includeAsync,
        private readonly bool $splitByModule
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getSelectors(): array
    {
        return $this->selectors;
    }

    /**
     * @inheritDoc
     */
    public function getFormats(): array
    {
        return $this->formats;
    }

    /**
     * @inheritDoc
     */
    public function getBaseFilename(): ?string
    {
        return $this->baseFilename;
    }

    /**
     * @inheritDoc
     */
    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    /**
     * @inheritDoc
     */
    public function isIncludeAsync(): bool
    {
        return $this->includeAsync;
    }

    /**
     * @inheritDoc
     */
    public function isSplitByModule(): bool
    {
        return $this->splitByModule;
    }

    /**
     * @inheritDoc
     */
    public function getSerialization(): string
    {
        return $this->serialization;
    }
}
