<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Export;

use Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface;
use Muon\ApiSchemaExport\Api\Data\OperationInterface;
use Muon\ApiSchemaExport\Model\Data\ApiSurface;
use Muon\ApiSchemaExport\Model\Webapi\TypeCollector;

/**
 * Narrows a resolved surface: by operation kind, and into one surface per module.
 *
 * Type definitions are recollected for each narrowed surface rather than copied wholesale, so a
 * single-module file carries only the schemas its own endpoints reach. The reflection pass has
 * already happened by this point, so recollecting is an in-memory walk, not more reflection.
 */
class SurfaceSplitter
{
    /**
     * @param \Muon\ApiSchemaExport\Model\Webapi\TypeCollector $typeCollector
     */
    public function __construct(private readonly TypeCollector $typeCollector)
    {
    }

    /**
     * Drop the asynchronous and bulk variants from a surface.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface $surface
     * @return \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface
     */
    public function withoutAsync(ApiSurfaceInterface $surface): ApiSurfaceInterface
    {
        $operations = array_values(
            array_filter(
                $surface->getOperations(),
                static fn (OperationInterface $operation): bool
                    => $operation->getKind() === OperationInterface::KIND_SYNC
            )
        );

        return $this->rebuild($operations, $surface->getModuleNames());
    }

    /**
     * Split a surface into one surface per selected module.
     *
     * An operation declared by two selected modules appears in both, which keeps each per-module
     * file a complete description of that module's own surface.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface $surface
     * @return array<string,\Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface> Keyed by module name.
     */
    public function byModule(ApiSurfaceInterface $surface): array
    {
        $selected = $surface->getModuleNames();
        $grouped = [];

        foreach ($surface->getOperations() as $operation) {
            foreach (array_intersect($operation->getModuleNames(), $selected) as $moduleName) {
                $grouped[$moduleName][] = $operation;
            }
        }

        ksort($grouped);

        $surfaces = [];
        foreach ($grouped as $moduleName => $operations) {
            $surfaces[(string)$moduleName] = $this->rebuild($operations, [(string)$moduleName]);
        }

        return $surfaces;
    }

    /**
     * Rebuild a surface around an externally narrowed operation set.
     *
     * Used by consumers that filter operations for reasons of their own — the admin front-end
     * removes endpoints the signed-in administrator may not call.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface $surface
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface[] $operations
     * @return \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface
     */
    public function withOperations(ApiSurfaceInterface $surface, array $operations): ApiSurfaceInterface
    {
        return $this->rebuild(array_values($operations), $surface->getModuleNames());
    }

    /**
     * Rebuild a surface around a narrowed operation set.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface[] $operations
     * @param string[] $moduleNames
     * @return \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface
     */
    private function rebuild(array $operations, array $moduleNames): ApiSurfaceInterface
    {
        return new ApiSurface($operations, $moduleNames, $this->typeCollector->collect($operations));
    }
}
