<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Api;

/**
 * Registry of the available renderers.
 *
 * @api
 */
interface RendererPoolInterface
{
    /**
     * Get one renderer by code.
     *
     * @param string $code
     * @return \Muon\ApiSchemaExport\Api\RendererInterface
     * @throws \Magento\Framework\Exception\InputException When no renderer is registered for the code.
     */
    public function get(string $code): RendererInterface;

    /**
     * Get every registered renderer code.
     *
     * @return string[]
     */
    public function getCodes(): array;

    /**
     * Get every registered renderer, keyed by code.
     *
     * @return array<string,\Muon\ApiSchemaExport\Api\RendererInterface>
     */
    public function getAll(): array;
}
