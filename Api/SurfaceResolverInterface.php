<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Api;

use Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface;

/**
 * Builds the renderable REST surface for a module selection.
 *
 * @api
 */
interface SurfaceResolverInterface
{
    /**
     * Resolve selectors to the complete renderable surface.
     *
     * Always includes the asynchronous and bulk variants when Magento_WebapiAsync is enabled.
     * Deciding which kinds reach a given document is a presentation concern, so callers filter on
     * OperationInterface::getKind() rather than asking the resolver to omit them.
     *
     * @param string[] $selectors
     * @return \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface
     * @throws \Magento\Framework\Exception\InputException When no non-blank selector is supplied.
     * @throws \Magento\Framework\Exception\NoSuchEntityException When a selector matches nothing.
     */
    public function resolve(array $selectors): ApiSurfaceInterface;
}
