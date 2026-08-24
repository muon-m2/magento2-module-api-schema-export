<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model;

use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface;
use Muon\ApiSchemaExport\Api\Data\OperationInterface;
use Muon\ApiSchemaExport\Api\ModuleSelectorInterface;
use Muon\ApiSchemaExport\Api\SurfaceResolverInterface;
use Muon\ApiSchemaExport\Model\Data\ApiSurface;
use Muon\ApiSchemaExport\Model\Webapi\OperationBuilder;
use Muon\ApiSchemaExport\Model\Webapi\RouteModuleMap;
use Muon\ApiSchemaExport\Model\Webapi\TypeCollector;

/**
 * Turns a module selection into a complete, self-contained render input.
 */
class SurfaceResolver implements SurfaceResolverInterface
{
    /**
     * Dispatched once per resolve, before type collection.
     *
     * Observers receive the selectors, the resolved module names, and a transport data object
     * holding the operations array under the "operations" key. Replacing that array is how an
     * observer adds, removes or annotates operations. The array is read back and re-validated
     * afterwards, so any entry that is not an OperationInterface is discarded rather than
     * reaching a renderer. Type collection runs after the event, so operations added by an
     * observer still get their schema definitions.
     */
    public const EVENT_SURFACE_RESOLVED = 'muon_api_schema_export_surface_resolved';

    /**
     * @param \Muon\ApiSchemaExport\Api\ModuleSelectorInterface $moduleSelector
     * @param \Muon\ApiSchemaExport\Model\Webapi\RouteModuleMap $routeModuleMap
     * @param \Muon\ApiSchemaExport\Model\Webapi\OperationBuilder $operationBuilder
     * @param \Muon\ApiSchemaExport\Model\Webapi\TypeCollector $typeCollector
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Framework\DataObjectFactory $dataObjectFactory
     */
    public function __construct(
        private readonly ModuleSelectorInterface $moduleSelector,
        private readonly RouteModuleMap $routeModuleMap,
        private readonly OperationBuilder $operationBuilder,
        private readonly TypeCollector $typeCollector,
        private readonly EventManagerInterface $eventManager,
        private readonly DataObjectFactory $dataObjectFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(array $selectors): ApiSurfaceInterface
    {
        $modules = $this->moduleSelector->match($selectors);
        $operations = $this->operationBuilder->buildAll($this->routeModuleMap->get(), $modules);
        $operations = $this->applySurfaceEvent($operations, $selectors, $modules);

        return new ApiSurface($operations, $modules, $this->typeCollector->collect($operations));
    }

    /**
     * Dispatch the extension event and read the operations back.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface[] $operations
     * @param string[] $selectors
     * @param string[] $modules
     * @return \Muon\ApiSchemaExport\Api\Data\OperationInterface[]
     */
    private function applySurfaceEvent(array $operations, array $selectors, array $modules): array
    {
        $transport = $this->dataObjectFactory->create(['data' => ['operations' => $operations]]);

        $this->eventManager->dispatch(self::EVENT_SURFACE_RESOLVED, [
            'selectors' => $selectors,
            'modules' => $modules,
            'transport' => $transport,
        ]);

        return array_values(
            array_filter(
                (array)$transport->getData('operations'),
                static fn ($operation): bool => $operation instanceof OperationInterface
            )
        );
    }
}
