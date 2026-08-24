<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model\Webapi;

use Magento\Framework\Module\Manager;
use Muon\ApiSchemaExport\Api\Data\OperationInterface;
use Muon\ApiSchemaExport\Model\Webapi\AsyncRouteDeriver;
use Muon\ApiSchemaExport\Test\Unit\OperationFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @see \Muon\ApiSchemaExport\Model\Webapi\AsyncRouteDeriver
 */
class AsyncRouteDeriverTest extends TestCase
{
    use OperationFixtureTrait;

    /**
     * Every non-GET route gains exactly two variants — Magento_WebapiAsync's own rule.
     *
     * @param string $httpMethod
     * @dataProvider writeMethodProvider
     */
    #[DataProvider('writeMethodProvider')]
    public function testNonGetRoutesGainBothVariants(string $httpMethod): void
    {
        $deriver = $this->makeDeriver(true);
        $variants = $deriver->derive($this->makeOperation([
            'route' => '/V1/products',
            'httpMethod' => $httpMethod,
        ]));

        self::assertCount(2, $variants);
        self::assertSame('/async/V1/products', $variants[0]->getRoute());
        self::assertSame(OperationInterface::KIND_ASYNC, $variants[0]->getKind());
        self::assertSame('/async/bulk/V1/products', $variants[1]->getRoute());
        self::assertSame(OperationInterface::KIND_ASYNC_BULK, $variants[1]->getKind());
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function writeMethodProvider(): array
    {
        return ['POST' => ['POST'], 'PUT' => ['PUT'], 'DELETE' => ['DELETE'], 'PATCH' => ['PATCH']];
    }

    /**
     * A GET route reads state, so it has no asynchronous form.
     */
    public function testGetRoutesGainNoVariant(): void
    {
        $deriver = $this->makeDeriver(true);

        self::assertSame([], $deriver->derive($this->makeOperation(['httpMethod' => 'GET'])));
    }

    /**
     * With Magento_WebapiAsync disabled the async surface does not exist.
     */
    public function testNothingIsDerivedWhenTheModuleIsDisabled(): void
    {
        $deriver = $this->makeDeriver(false);

        self::assertFalse($deriver->isAvailable());
        self::assertSame([], $deriver->derive($this->makeOperation(['httpMethod' => 'POST'])));
    }

    /**
     * Both variants return the async response contract, not the synchronous return type.
     */
    public function testVariantsReturnTheAsyncResponseContract(): void
    {
        $variants = $this->makeDeriver(true)->derive($this->makeOperation(['httpMethod' => 'POST']));

        foreach ($variants as $variant) {
            self::assertSame(
                'Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface',
                $variant->getOutputParameters()['result']['type']
            );
        }
    }

    /**
     * Attribution, ACL and input parameters carry over unchanged.
     */
    public function testVariantsInheritAttributionAndAcl(): void
    {
        $operation = $this->makeOperation(['httpMethod' => 'POST']);
        $variants = $this->makeDeriver(true)->derive($operation);

        foreach ($variants as $variant) {
            self::assertSame($operation->getModuleNames(), $variant->getModuleNames());
            self::assertSame($operation->getAclResources(), $variant->getAclResources());
            self::assertSame($operation->getInputParameters(), $variant->getInputParameters());
        }
    }

    /**
     * Build a deriver with the async module reported enabled or disabled.
     *
     * @param bool $enabled
     * @return \Muon\ApiSchemaExport\Model\Webapi\AsyncRouteDeriver
     */
    private function makeDeriver(bool $enabled): AsyncRouteDeriver
    {
        $moduleManager = $this->createStub(Manager::class);
        $moduleManager->method('isEnabled')->willReturn($enabled);

        return new AsyncRouteDeriver($moduleManager);
    }
}
