<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Api;

use Muon\ApiSchemaExport\Api\Data\ExportRequestInterface;
use Muon\ApiSchemaExport\Api\Data\ExportResultInterface;

/**
 * Façade over surface resolution and rendering, shared by every front-end.
 *
 * @api
 */
interface ExportManagerInterface
{
    /**
     * Resolve the requested modules and render them into the requested formats.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\ExportRequestInterface $request
     * @return \Muon\ApiSchemaExport\Api\Data\ExportResultInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException When a selector matches nothing.
     * @throws \Magento\Framework\Exception\InputException When a requested format is unknown.
     */
    public function export(ExportRequestInterface $request): ExportResultInterface;
}
