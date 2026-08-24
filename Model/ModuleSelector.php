<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\ModuleListInterface;
use Muon\ApiSchemaExport\Api\ModuleSelectorInterface;

/**
 * Matches selector strings against the enabled module list.
 *
 * Only enabled modules are ever considered: the module list holds exactly the modules Magento has
 * loaded, which is also the set whose webapi.xml the config reader has merged.
 */
class ModuleSelector implements ModuleSelectorInterface
{
    /**
     * Selector matching every enabled module.
     */
    private const SELECTOR_ALL = '*';

    /**
     * @param \Magento\Framework\Module\ModuleListInterface $moduleList
     */
    public function __construct(private readonly ModuleListInterface $moduleList)
    {
    }

    /**
     * @inheritDoc
     */
    public function match(array $selectors): array
    {
        $available = $this->moduleList->getNames();
        $matched = [];
        $unmatched = [];
        $considered = 0;

        foreach ($selectors as $selector) {
            $selector = trim((string)$selector);
            if ($selector === '') {
                continue;
            }

            $considered++;
            $hits = $this->matchOne($selector, $available);
            if ($hits === []) {
                $unmatched[] = $selector;
                continue;
            }
            $matched[] = $hits;
        }

        // An empty or all-blank selector list would otherwise resolve to zero modules and render
        // an empty but perfectly valid document, which reads as success. Fail loudly instead.
        if ($considered === 0) {
            throw new InputException(__('At least one module selector is required.'));
        }

        if ($unmatched !== []) {
            throw new NoSuchEntityException(
                __('No enabled module matches: %1', implode(', ', $unmatched))
            );
        }

        $matched = $matched === [] ? [] : array_merge(...$matched);
        $matched = array_values(array_unique($matched));
        sort($matched);

        return $matched;
    }

    /**
     * Match a single selector against the available module names.
     *
     * @param string $selector
     * @param string[] $available
     * @return string[]
     */
    private function matchOne(string $selector, array $available): array
    {
        if ($selector === self::SELECTOR_ALL) {
            return $available;
        }

        if (str_contains($selector, self::SELECTOR_ALL)) {
            return array_values(
                array_filter($available, static fn (string $name): bool => fnmatch($selector, $name))
            );
        }

        // A selector with no underscore is a vendor namespace, so Muon means every Muon_* module.
        if (!str_contains($selector, '_')) {
            $prefix = $selector . '_';
            return array_values(
                array_filter($available, static fn (string $name): bool => str_starts_with($name, $prefix))
            );
        }

        return in_array($selector, $available, true) ? [$selector] : [];
    }
}
