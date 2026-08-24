<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model\Export;

use Magento\Framework\Reflection\TypeProcessor;
use Muon\ApiSchemaExport\Api\Data\OperationInterface;
use Muon\ApiSchemaExport\Model\Data\ApiSurface;
use Muon\ApiSchemaExport\Model\Export\SurfaceSplitter;
use Muon\ApiSchemaExport\Model\Webapi\TypeCollector;
use Muon\ApiSchemaExport\Test\Unit\OperationFixtureTrait;
use PHPUnit\Framework\TestCase;

/**
 * @see \Muon\ApiSchemaExport\Model\Export\SurfaceSplitter
 */
class SurfaceSplitterTest extends TestCase
{
    use OperationFixtureTrait;

    /**
     * @var SurfaceSplitter
     */
    private SurfaceSplitter $splitter;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $typeProcessor = $this->createStub(TypeProcessor::class);
        $typeProcessor->method('getTypesData')->willReturn([]);
        $typeProcessor->method('isTypeSimple')->willReturn(true);

        $this->splitter = new SurfaceSplitter(new TypeCollector($typeProcessor));
    }

    /**
     * Dropping async keeps only the synchronous operations.
     */
    public function testWithoutAsyncKeepsOnlySyncOperations(): void
    {
        $surface = new ApiSurface(
            [
                $this->makeOperation(['kind' => OperationInterface::KIND_SYNC]),
                $this->makeOperation(['kind' => OperationInterface::KIND_ASYNC]),
                $this->makeOperation(['kind' => OperationInterface::KIND_ASYNC_BULK]),
            ],
            ['Magento_Catalog'],
            []
        );

        $filtered = $this->splitter->withoutAsync($surface);

        self::assertCount(1, $filtered->getOperations());
        self::assertSame(OperationInterface::KIND_SYNC, $filtered->getOperations()[0]->getKind());
    }

    /**
     * Each selected module gets a surface holding only its own operations.
     */
    public function testByModuleGroupsOperationsPerModule(): void
    {
        $surface = new ApiSurface(
            [
                $this->makeOperation(['moduleNames' => ['Magento_Catalog'], 'route' => '/V1/a']),
                $this->makeOperation(['moduleNames' => ['Magento_Sales'], 'route' => '/V1/b']),
            ],
            ['Magento_Catalog', 'Magento_Sales'],
            []
        );

        $split = $this->splitter->byModule($surface);

        self::assertSame(['Magento_Catalog', 'Magento_Sales'], array_keys($split));
        self::assertSame('/V1/a', $split['Magento_Catalog']->getOperations()[0]->getRoute());
        self::assertSame(['Magento_Sales'], $split['Magento_Sales']->getModuleNames());
    }

    /**
     * An operation declared by two selected modules lands in both files.
     *
     * That is what keeps each per-module document a complete description of that module.
     */
    public function testSharedOperationAppearsUnderEveryOwner(): void
    {
        $surface = new ApiSurface(
            [$this->makeOperation(['moduleNames' => ['Magento_Catalog', 'Muon_CatalogExtra']])],
            ['Magento_Catalog', 'Muon_CatalogExtra'],
            []
        );

        $split = $this->splitter->byModule($surface);

        self::assertCount(1, $split['Magento_Catalog']->getOperations());
        self::assertCount(1, $split['Muon_CatalogExtra']->getOperations());
    }

    /**
     * A module that was not selected does not become a group of its own.
     */
    public function testUnselectedOwnersAreIgnored(): void
    {
        $surface = new ApiSurface(
            [$this->makeOperation(['moduleNames' => ['Magento_Catalog', 'Magento_Downloadable']])],
            ['Magento_Catalog'],
            []
        );

        self::assertSame(['Magento_Catalog'], array_keys($this->splitter->byModule($surface)));
    }

    /**
     * withOperations rebuilds a surface around an externally filtered set.
     */
    public function testWithOperationsRebuildsAroundTheGivenSet(): void
    {
        $surface = new ApiSurface(
            [$this->makeOperation(), $this->makeOperation(['route' => '/V1/other'])],
            ['Magento_Catalog'],
            []
        );

        $narrowed = $this->splitter->withOperations($surface, [$surface->getOperations()[1]]);

        self::assertCount(1, $narrowed->getOperations());
        self::assertSame('/V1/other', $narrowed->getOperations()[0]->getRoute());
        self::assertSame(['Magento_Catalog'], $narrowed->getModuleNames());
    }
}
