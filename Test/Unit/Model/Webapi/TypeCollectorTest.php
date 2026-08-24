<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model\Webapi;

use Magento\Framework\Reflection\TypeProcessor;
use Muon\ApiSchemaExport\Model\Webapi\TypeCollector;
use Muon\ApiSchemaExport\Test\Unit\OperationFixtureTrait;
use PHPUnit\Framework\TestCase;

/**
 * @see \Muon\ApiSchemaExport\Model\Webapi\TypeCollector
 */
class TypeCollectorTest extends TestCase
{
    use OperationFixtureTrait;

    /**
     * Only types reachable from the selected operations are collected.
     *
     * The type processor holds definitions for the whole installation once service metadata has
     * been warmed. Emitting all of them would bury a two-module export under thousands of
     * irrelevant schemas.
     */
    public function testOnlyReachableTypesAreCollected(): void
    {
        $all = [
            'ProductInterface' => ['parameters' => ['sku' => ['type' => 'string']]],
            'UnrelatedInterface' => ['parameters' => ['x' => ['type' => 'string']]],
        ];

        $collected = $this->makeCollector($all)->collect([
            $this->makeOperation([
                'inputParameters' => ['product' => ['type' => 'ProductInterface']],
                'outputParameters' => [],
            ]),
        ]);

        self::assertSame(['ProductInterface'], array_keys($collected));
    }

    /**
     * Collection follows each definition's own members transitively.
     */
    public function testTransitiveTypesAreFollowed(): void
    {
        $all = [
            'ProductInterface' => ['parameters' => ['extension' => ['type' => 'ExtensionInterface']]],
            'ExtensionInterface' => ['parameters' => ['stock' => ['type' => 'StockInterface']]],
            'StockInterface' => ['parameters' => ['qty' => ['type' => 'float']]],
            'UnrelatedInterface' => ['parameters' => []],
        ];

        $collected = $this->makeCollector($all)->collect([
            $this->makeOperation([
                'inputParameters' => ['product' => ['type' => 'ProductInterface']],
                'outputParameters' => [],
            ]),
        ]);

        self::assertSame(
            ['ExtensionInterface', 'ProductInterface', 'StockInterface'],
            array_keys($collected)
        );
    }

    /**
     * A cyclic definition graph terminates.
     */
    public function testCyclicDefinitionsTerminate(): void
    {
        $all = [
            'A' => ['parameters' => ['b' => ['type' => 'B']]],
            'B' => ['parameters' => ['a' => ['type' => 'A']]],
        ];

        $collected = $this->makeCollector($all)->collect([
            $this->makeOperation([
                'inputParameters' => ['a' => ['type' => 'A']],
                'outputParameters' => [],
            ]),
        ]);

        self::assertSame(['A', 'B'], array_keys($collected));
    }

    /**
     * Output parameters seed collection too, not only inputs.
     */
    public function testOutputTypesAreCollected(): void
    {
        $all = ['ResultInterface' => ['parameters' => []]];

        $collected = $this->makeCollector($all)->collect([
            $this->makeOperation([
                'inputParameters' => [],
                'outputParameters' => ['result' => ['type' => 'ResultInterface']],
            ]),
        ]);

        self::assertSame(['ResultInterface'], array_keys($collected));
    }

    /**
     * With no operations there is nothing to collect.
     */
    public function testNoOperationsCollectNothing(): void
    {
        self::assertSame([], $this->makeCollector(['A' => ['parameters' => []]])->collect([]));
    }

    /**
     * Build a collector over a fake type-processor holding the given definitions.
     *
     * @param array<string,mixed> $all
     * @return \Muon\ApiSchemaExport\Model\Webapi\TypeCollector
     */
    private function makeCollector(array $all): TypeCollector
    {
        $simple = ['string', 'int', 'float', 'bool', 'mixed', ''];

        $typeProcessor = $this->createStub(TypeProcessor::class);
        $typeProcessor->method('getTypesData')->willReturn($all);
        $typeProcessor->method('isTypeSimple')->willReturnCallback(
            static fn (string $type): bool => in_array($type, $simple, true)
        );
        $typeProcessor->method('isTypeAny')->willReturn(false);

        return new TypeCollector($typeProcessor);
    }
}
