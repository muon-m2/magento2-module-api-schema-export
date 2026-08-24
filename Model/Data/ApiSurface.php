<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Data;

use Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface;

/**
 * Immutable, self-contained render input.
 *
 * Everything a renderer needs lives here, so no renderer has to reach back into Magento's Webapi
 * model to finish its job.
 */
class ApiSurface implements ApiSurfaceInterface
{
    /**
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface[] $operations
     * @param string[] $moduleNames
     * @param array<string,mixed> $types
     */
    public function __construct(
        private readonly array $operations,
        private readonly array $moduleNames,
        private readonly array $types
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getOperations(): array
    {
        return $this->operations;
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
    public function getTypes(): array
    {
        return $this->types;
    }
}
