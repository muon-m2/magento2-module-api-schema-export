<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Export;

use Magento\Framework\UrlInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface;
use Muon\ApiSchemaExport\Api\Data\ExportRequestInterface;
use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;
use Muon\ApiSchemaExport\Model\Data\RenderContext;

/**
 * Resolves the per-render inputs a request leaves unspecified.
 */
class RenderContextBuilder
{
    /**
     * Longest module list spelled out in a document title before it is summarised.
     */
    private const MAX_TITLED_MODULES = 3;

    /**
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(private readonly StoreManagerInterface $storeManager)
    {
    }

    /**
     * Build the render context for a request and its resolved surface.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\ExportRequestInterface $request
     * @param \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface $surface
     * @return \Muon\ApiSchemaExport\Api\Data\RenderContextInterface
     */
    public function build(
        ExportRequestInterface $request,
        ApiSurfaceInterface $surface
    ): RenderContextInterface {
        return new RenderContext(
            $this->resolveBaseUrl($request),
            $this->buildTitle($surface),
            $request->getSerialization() === RenderContextInterface::FORMAT_YAML
                ? RenderContextInterface::FORMAT_YAML
                : RenderContextInterface::FORMAT_JSON
        );
    }

    /**
     * Resolve the API root the generated documents should target.
     *
     * The default store view is preferred over the current store: in an admin request the current
     * store is the admin store, whose base URL is not the storefront root a REST client wants.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\ExportRequestInterface $request
     * @return string Without a trailing slash.
     */
    private function resolveBaseUrl(ExportRequestInterface $request): string
    {
        $override = trim((string)$request->getBaseUrl());
        if ($override !== '') {
            return rtrim($override, '/');
        }

        $store = $this->resolveStore();

        // StoreInterface does not declare getBaseUrl(); only the concrete Store does.
        return $store instanceof Store
            ? rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/')
            : '';
    }

    /**
     * Get the store whose base URL the documents should carry.
     *
     * @return \Magento\Store\Api\Data\StoreInterface|null
     */
    private function resolveStore(): ?\Magento\Store\Api\Data\StoreInterface
    {
        try {
            return $this->storeManager->getDefaultStoreView() ?? $this->storeManager->getStore();
        } catch (NoSuchEntityException $exception) {
            return null;
        }
    }

    /**
     * Build a document title naming what was exported.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface $surface
     * @return string
     */
    private function buildTitle(ApiSurfaceInterface $surface): string
    {
        $modules = $surface->getModuleNames();
        if ($modules === []) {
            return 'Magento REST API';
        }
        if (count($modules) <= self::MAX_TITLED_MODULES) {
            return 'Magento REST API — ' . implode(', ', $modules);
        }

        return sprintf('Magento REST API — %s and %d more', $modules[0], count($modules) - 1);
    }
}
