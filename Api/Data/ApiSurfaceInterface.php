<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Api\Data;

/**
 * The resolved, renderable REST surface for one selection of modules.
 *
 * @api
 */
interface ApiSurfaceInterface
{
    /**
     * Get every operation in the surface.
     *
     * @return \Muon\ApiSchemaExport\Api\Data\OperationInterface[]
     */
    public function getOperations(): array;

    /**
     * Get the module names the selection resolved to.
     *
     * @return string[]
     */
    public function getModuleNames(): array;

    /**
     * Get reflected type data for every request and response type reachable from the operations.
     *
     * Shaped as Magento\Framework\Reflection\TypeProcessor::getTypesData() returns it, so renderers
     * can build schema definitions without repeating the reflection pass.
     *
     * @return array<string,mixed>
     */
    public function getTypes(): array;
}
