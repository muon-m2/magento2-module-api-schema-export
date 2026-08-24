<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Api;

/**
 * Resolves module selector strings to concrete enabled module names.
 *
 * @api
 */
interface ModuleSelectorInterface
{
    /**
     * Resolve selectors to the enabled modules they match.
     *
     * Four selector forms are supported: an exact module name (Muon_FileAttachment), a vendor
     * namespace with no underscore (Muon, matching Muon_*), an fnmatch wildcard
     * (Magento_Catalog*), and * for every enabled module.
     *
     * @param string[] $selectors
     * @return string[] Sorted, de-duplicated module names.
     * @throws \Magento\Framework\Exception\InputException When no non-blank selector is supplied.
     * @throws \Magento\Framework\Exception\NoSuchEntityException When a selector matches nothing.
     */
    public function match(array $selectors): array;
}
