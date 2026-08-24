<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit;

use Muon\ApiSchemaExport\Api\Data\OperationInterface;
use Muon\ApiSchemaExport\Model\Data\Operation;

/**
 * Builds Operation fixtures without repeating thirteen constructor arguments in every test.
 */
trait OperationFixtureTrait
{
    /**
     * Build an operation, overriding only what a test cares about.
     *
     * @param array<string,mixed> $overrides
     * @return \Muon\ApiSchemaExport\Api\Data\OperationInterface
     */
    private function makeOperation(array $overrides = []): OperationInterface
    {
        $values = array_merge([
            'moduleNames' => ['Magento_Catalog'],
            'route' => '/V1/products/:sku',
            'httpMethod' => 'PUT',
            'serviceClass' => 'Magento\Catalog\Api\ProductRepositoryInterface',
            'serviceMethod' => 'save',
            'aclResources' => ['Magento_Catalog::products'],
            'secure' => false,
            'kind' => OperationInterface::KIND_SYNC,
            'description' => 'Save a product.',
            'inputParameters' => [
                'sku' => ['type' => 'string', 'required' => true, 'documentation' => 'The SKU.'],
                'product' => [
                    'type' => 'CatalogDataProductInterface',
                    'required' => true,
                    'documentation' => 'The product.',
                ],
            ],
            'outputParameters' => [
                'result' => [
                    'type' => 'CatalogDataProductInterface',
                    'required' => true,
                    'documentation' => 'The saved product.',
                ],
            ],
            'thrownExceptions' => ['Magento\Framework\Exception\CouldNotSaveException'],
            'forcedParameters' => [],
        ], $overrides);

        return new Operation(
            $values['moduleNames'],
            $values['route'],
            $values['httpMethod'],
            $values['serviceClass'],
            $values['serviceMethod'],
            $values['aclResources'],
            $values['secure'],
            $values['kind'],
            $values['description'],
            $values['inputParameters'],
            $values['outputParameters'],
            $values['thrownExceptions'],
            $values['forcedParameters']
        );
    }

    /**
     * Type definitions matching the fixture operation's parameters.
     *
     * @return array<string,mixed>
     */
    private function makeTypes(): array
    {
        return [
            'CatalogDataProductInterface' => [
                'documentation' => 'Product interface.',
                'parameters' => [
                    'sku' => ['type' => 'string', 'required' => true, 'documentation' => 'SKU.'],
                    'price' => ['type' => 'float', 'required' => false, 'documentation' => 'Price.'],
                ],
            ],
        ];
    }
}
