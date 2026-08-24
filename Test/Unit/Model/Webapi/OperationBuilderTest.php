<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model\Webapi;

use Magento\Webapi\Model\Config as WebapiConfig;
use Magento\Webapi\Model\Config\Converter;
use Magento\Webapi\Model\Config\ClassReflector;
use Muon\ApiSchemaExport\Api\Data\OperationInterface;
use Muon\ApiSchemaExport\Model\Webapi\AsyncRouteDeriver;
use Muon\ApiSchemaExport\Model\Webapi\OperationBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @see \Muon\ApiSchemaExport\Model\Webapi\OperationBuilder
 */
class OperationBuilderTest extends TestCase
{
    private const SERVICE_CLASS = 'Magento\Catalog\Api\ProductRepositoryInterface';

    /**
     * A route in a selected module becomes an operation carrying its reflected metadata.
     */
    public function testRouteInASelectedModuleBecomesAnOperation(): void
    {
        $operations = $this->makeBuilder()->buildAll(
            ['PUT /V1/products/:sku' => ['Magento_Catalog']],
            ['Magento_Catalog']
        );

        self::assertCount(1, $operations);
        $operation = $operations[0];
        self::assertSame('/V1/products/:sku', $operation->getRoute());
        self::assertSame('PUT', $operation->getHttpMethod());
        self::assertSame(self::SERVICE_CLASS, $operation->getServiceClass());
        self::assertSame('save', $operation->getServiceMethod());
        self::assertSame(['Magento_Catalog::products'], $operation->getAclResources());
        self::assertSame(OperationInterface::KIND_SYNC, $operation->getKind());
    }

    /**
     * Documentation, parameters and exceptions all reach the operation.
     *
     * Without these a renderer would have to reach back into Magento's Webapi model itself.
     */
    public function testReflectedMetadataReachesTheOperation(): void
    {
        $operation = $this->makeBuilder()->buildAll(
            ['PUT /V1/products/:sku' => ['Magento_Catalog']],
            ['Magento_Catalog']
        )[0];

        self::assertSame('Save a product.', $operation->getDescription());
        self::assertArrayHasKey('product', $operation->getInputParameters());
        self::assertArrayHasKey('result', $operation->getOutputParameters());
        self::assertSame(
            ['Magento\Framework\Exception\CouldNotSaveException'],
            $operation->getThrownExceptions()
        );
    }

    /**
     * A route belonging to no selected module is skipped.
     */
    public function testRoutesOutsideTheSelectionAreSkipped(): void
    {
        $operations = $this->makeBuilder()->buildAll(
            ['PUT /V1/products/:sku' => ['Magento_Catalog']],
            ['Magento_Sales']
        );

        self::assertSame([], $operations);
    }

    /**
     * A route with no attribution at all is skipped.
     */
    public function testUnattributedRoutesAreSkipped(): void
    {
        self::assertSame([], $this->makeBuilder()->buildAll([], ['Magento_Catalog']));
    }

    /**
     * Async variants are appended after their synchronous parent.
     */
    public function testAsyncVariantsFollowTheirParent(): void
    {
        $operations = $this->makeBuilder(true)->buildAll(
            ['PUT /V1/products/:sku' => ['Magento_Catalog']],
            ['Magento_Catalog']
        );

        self::assertCount(3, $operations);
        self::assertSame(OperationInterface::KIND_SYNC, $operations[0]->getKind());
        self::assertSame(OperationInterface::KIND_ASYNC, $operations[1]->getKind());
        self::assertSame(OperationInterface::KIND_ASYNC_BULK, $operations[2]->getKind());
    }

    /**
     * A route naming no service class yields nothing rather than a half-built operation.
     */
    public function testRouteWithoutAServiceIsSkipped(): void
    {
        $builder = $this->makeBuilder(false, [
            '/V1/broken' => ['GET' => [Converter::KEY_SERVICE => []]],
        ]);

        self::assertSame([], $builder->buildAll(['GET /V1/broken' => ['Magento_Catalog']], ['Magento_Catalog']));
    }

    /**
     * A contract Magento cannot reflect is logged, and the endpoint still exports.
     *
     * This is the regression guard for the defect the first live run surfaced. Two service methods
     * on this installation carry a bare array type that Magento's reflection rejects. Reflecting
     * only what the selection reaches keeps that blast radius to the offending operation; the
     * route, method, ACL and attribution are all still known, so the endpoint is worth emitting
     * without its parameter schema rather than dropping it — or, as before, killing the whole run.
     */
    public function testUnreflectableContractIsLoggedButTheRouteSurvives(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $classReflector = $this->createStub(ClassReflector::class);
        $classReflector->method('reflectClassMethods')->willThrowException(
            new \LogicException('The "array" class doesn\'t exist and the namespace must be specified.')
        );

        $builder = new OperationBuilder(
            $this->makeWebapiConfig($this->defaultRoutes()),
            $classReflector,
            $this->makeDeriver(false),
            $logger
        );

        $operations = $builder->buildAll(['PUT /V1/products/:sku' => ['Magento_Catalog']], ['Magento_Catalog']);

        self::assertCount(1, $operations);
        self::assertSame('', $operations[0]->getDescription());
        self::assertSame([], $operations[0]->getInputParameters());
    }

    /**
     * Reflection is memoised, so a service reached by several routes is reflected once.
     */
    public function testReflectionIsMemoisedPerServiceMethod(): void
    {
        $classReflector = $this->createMock(ClassReflector::class);
        $classReflector->expects(self::once())->method('reflectClassMethods')->willReturn([]);

        $routes = $this->defaultRoutes();
        $routes['/V1/products'] = ['POST' => $routes['/V1/products/:sku']['PUT']];

        $builder = new OperationBuilder(
            $this->makeWebapiConfig($routes),
            $classReflector,
            $this->makeDeriver(false),
            $this->createStub(LoggerInterface::class)
        );

        $operations = $builder->buildAll(
            ['PUT /V1/products/:sku' => ['Magento_Catalog'], 'POST /V1/products' => ['Magento_Catalog']],
            ['Magento_Catalog']
        );

        self::assertCount(2, $operations);
    }

    /**
     * Build a builder over the default route table and service metadata.
     *
     * @param bool $asyncEnabled
     * @param array<string,mixed>|null $routes
     * @return \Muon\ApiSchemaExport\Model\Webapi\OperationBuilder
     */
    private function makeBuilder(bool $asyncEnabled = false, ?array $routes = null): OperationBuilder
    {
        $classReflector = $this->createStub(ClassReflector::class);
        $classReflector->method('reflectClassMethods')->willReturn([
            'save' => [
                'documentation' => 'Save a product.',
                'interface' => [
                    'in' => ['parameters' => ['product' => ['type' => 'ProductInterface']]],
                    'out' => [
                        'parameters' => ['result' => ['type' => 'ProductInterface']],
                        'throws' => ['Magento\Framework\Exception\CouldNotSaveException'],
                    ],
                ],
            ],
        ]);

        return new OperationBuilder(
            $this->makeWebapiConfig($routes ?? $this->defaultRoutes()),
            $classReflector,
            $this->makeDeriver($asyncEnabled),
            $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * The default merged route table.
     *
     * @return array<string,mixed>
     */
    private function defaultRoutes(): array
    {
        return [
            '/V1/products/:sku' => [
                'PUT' => [
                    Converter::KEY_SECURE => false,
                    Converter::KEY_SERVICE => [
                        Converter::KEY_SERVICE_CLASS => self::SERVICE_CLASS,
                        Converter::KEY_SERVICE_METHOD => 'save',
                    ],
                    Converter::KEY_ACL_RESOURCES => ['Magento_Catalog::products' => true],
                    Converter::KEY_DATA_PARAMETERS => [],
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $routes
     * @return \Magento\Webapi\Model\Config
     */
    private function makeWebapiConfig(array $routes): WebapiConfig
    {
        $config = $this->createStub(WebapiConfig::class);
        $config->method('getServices')->willReturn([Converter::KEY_ROUTES => $routes]);

        return $config;
    }

    /**
     * @param bool $enabled
     * @return \Muon\ApiSchemaExport\Model\Webapi\AsyncRouteDeriver
     */
    private function makeDeriver(bool $enabled): AsyncRouteDeriver
    {
        $moduleManager = $this->createStub(\Magento\Framework\Module\Manager::class);
        $moduleManager->method('isEnabled')->willReturn($enabled);

        return new AsyncRouteDeriver($moduleManager);
    }
}
