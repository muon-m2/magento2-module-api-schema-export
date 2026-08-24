<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Muon\ApiSchemaExport\Api\ModuleSelectorInterface;
use Muon\ApiSchemaExport\Model\SurfaceResolver;
use Muon\ApiSchemaExport\Model\Webapi\OperationBuilder;
use Muon\ApiSchemaExport\Model\Webapi\RouteModuleMap;
use Muon\ApiSchemaExport\Model\Webapi\TypeCollector;
use Muon\ApiSchemaExport\Test\Unit\OperationFixtureTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @see \Muon\ApiSchemaExport\Model\SurfaceResolver
 */
class SurfaceResolverTest extends TestCase
{
    use OperationFixtureTrait;

    /**
     * @var MockObject&EventManagerInterface
     */
    /**
     * @var MockObject
     */
    private MockObject $eventManager;

    /**
     * @var MockObject&OperationBuilder
     */
    /**
     * @var MockObject
     */
    private MockObject $operationBuilder;

    /**
     * @var SurfaceResolver
     */
    private SurfaceResolver $resolver;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $moduleSelector = $this->createStub(ModuleSelectorInterface::class);
        $moduleSelector->method('match')->willReturn(['Magento_Catalog']);

        $routeModuleMap = $this->createStub(RouteModuleMap::class);
        $routeModuleMap->method('get')->willReturn(['PUT /V1/products/:sku' => ['Magento_Catalog']]);

        $this->operationBuilder = $this->createMock(OperationBuilder::class);

        $typeCollector = $this->createStub(TypeCollector::class);
        $typeCollector->method('collect')->willReturn([]);

        $this->eventManager = $this->createMock(EventManagerInterface::class);

        $dataObjectFactory = $this->createStub(DataObjectFactory::class);
        $dataObjectFactory->method('create')->willReturnCallback(
            static fn (array $arguments = []): DataObject => new DataObject($arguments['data'] ?? [])
        );

        $this->resolver = new SurfaceResolver(
            $moduleSelector,
            $routeModuleMap,
            $this->operationBuilder,
            $typeCollector,
            $this->eventManager,
            $dataObjectFactory
        );
    }

    /**
     * The extension event fires exactly once per resolve.
     */
    public function testTheExtensionEventFiresOncePerResolve(): void
    {
        $this->operationBuilder->expects(self::once())->method('buildAll')->willReturn([$this->makeOperation()]);
        $this->eventManager->expects(self::once())
            ->method('dispatch')
            ->with(SurfaceResolver::EVENT_SURFACE_RESOLVED, self::anything());

        $this->resolver->resolve(['Magento_Catalog']);
    }

    /**
     * An observer can replace the operation list through the transport object.
     */
    public function testAnObserverCanReplaceTheOperations(): void
    {
        $replacement = $this->makeOperation(['route' => '/V1/replaced']);
        $this->operationBuilder->expects(self::once())->method('buildAll')->willReturn([$this->makeOperation()]);

        $this->eventManager->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(
                static function (string $name, array $data) use ($replacement): void {
                    $data['transport']->setData('operations', [$replacement]);
                }
            );

        $surface = $this->resolver->resolve(['Magento_Catalog']);

        self::assertCount(1, $surface->getOperations());
        self::assertSame('/V1/replaced', $surface->getOperations()[0]->getRoute());
    }

    /**
     * Anything an observer puts in that is not an operation is discarded, not rendered.
     */
    public function testNonOperationEntriesFromObserversAreDiscarded(): void
    {
        $this->operationBuilder->expects(self::once())->method('buildAll')->willReturn([$this->makeOperation()]);

        $this->eventManager->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(
                static function (string $name, array $data): void {
                    $data['transport']->setData('operations', ['a string', null, new \stdClass()]);
                }
            );

        self::assertSame([], $this->resolver->resolve(['Magento_Catalog'])->getOperations());
    }

    /**
     * The resolved module list reaches the surface.
     */
    public function testResolvedModulesReachTheSurface(): void
    {
        $this->operationBuilder->expects(self::once())->method('buildAll')->willReturn([$this->makeOperation()]);
        $this->eventManager->expects(self::once())->method('dispatch');

        self::assertSame(['Magento_Catalog'], $this->resolver->resolve(['Magento_Catalog'])->getModuleNames());
    }
}
