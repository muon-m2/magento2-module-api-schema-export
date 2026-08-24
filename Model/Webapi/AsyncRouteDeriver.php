<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Webapi;

use Magento\Framework\Module\Manager;
use Magento\Framework\Webapi\Rest\Request;
use Muon\ApiSchemaExport\Api\Data\OperationInterface;
use Muon\ApiSchemaExport\Model\Data\Operation;

/**
 * Derives the asynchronous and bulk variants of a synchronous operation.
 *
 * Magento_WebapiAsync does not declare these routes anywhere. Its Model\Config derives them from
 * the merged REST route table by one rule: every non-GET route gains an /async/V1 single-entity
 * variant and an /async/bulk/V1 array variant. That rule is reproduced here rather than depending
 * on the module's classes, which keeps Magento_WebapiAsync a soft dependency that can be absent
 * without breaking the export.
 */
class AsyncRouteDeriver
{
    /**
     * Module whose presence enables the async REST surface.
     */
    private const ASYNC_MODULE = 'Magento_WebapiAsync';

    /**
     * Route prefix for the single-entity asynchronous variant.
     */
    private const PREFIX_ASYNC = '/async';

    /**
     * Route prefix for the bulk asynchronous variant.
     */
    private const PREFIX_BULK = '/async/bulk';

    /**
     * Response contract both variants return.
     */
    private const ASYNC_RESPONSE_TYPE = \Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface::class;

    /**
     * @param \Magento\Framework\Module\Manager $moduleManager
     */
    public function __construct(private readonly Manager $moduleManager)
    {
    }

    /**
     * Whether the async surface exists on this installation.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->moduleManager->isEnabled(self::ASYNC_MODULE);
    }

    /**
     * Derive the async variants of one synchronous operation.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface $operation
     * @return \Muon\ApiSchemaExport\Api\Data\OperationInterface[] Empty when no variant applies.
     */
    public function derive(OperationInterface $operation): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        // GET routes read state, so they have no asynchronous form.
        if ($operation->getHttpMethod() === Request::HTTP_METHOD_GET) {
            return [];
        }

        return [
            $this->variant($operation, self::PREFIX_ASYNC, OperationInterface::KIND_ASYNC),
            $this->variant($operation, self::PREFIX_BULK, OperationInterface::KIND_ASYNC_BULK),
        ];
    }

    /**
     * Build one variant of an operation.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface $operation
     * @param string $prefix
     * @param string $kind
     * @return \Muon\ApiSchemaExport\Api\Data\OperationInterface
     */
    private function variant(OperationInterface $operation, string $prefix, string $kind): OperationInterface
    {
        $bulk = $kind === OperationInterface::KIND_ASYNC_BULK;
        $description = $operation->getDescription();
        $suffix = $bulk
            ? ' Accepts an array of entities and returns immediately with a bulk identifier.'
            : ' Queues the operation and returns immediately with a bulk identifier.';

        return new Operation(
            $operation->getModuleNames(),
            $prefix . $operation->getRoute(),
            $operation->getHttpMethod(),
            $operation->getServiceClass(),
            $operation->getServiceMethod(),
            $operation->getAclResources(),
            $operation->isSecure(),
            $kind,
            trim($description . $suffix),
            $operation->getInputParameters(),
            [
                'result' => [
                    'type' => self::ASYNC_RESPONSE_TYPE,
                    'required' => true,
                    'documentation' => 'Bulk identifier, accepted request items and any errors.',
                ],
            ],
            $operation->getThrownExceptions(),
            $operation->getForcedParameters()
        );
    }
}
