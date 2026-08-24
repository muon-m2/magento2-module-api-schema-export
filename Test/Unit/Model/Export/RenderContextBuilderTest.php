<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model\Export;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;
use Muon\ApiSchemaExport\Model\Data\ApiSurface;
use Muon\ApiSchemaExport\Model\Data\ExportRequest;
use Muon\ApiSchemaExport\Model\Export\RenderContextBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @see \Muon\ApiSchemaExport\Model\Export\RenderContextBuilder
 */
class RenderContextBuilderTest extends TestCase
{
    /**
     * The default store view's base URL is used, with the trailing slash removed.
     *
     * The default store view rather than the current store: in an admin request the current store
     * is the admin store, whose base URL is not the storefront root a REST client wants.
     */
    public function testBaseUrlComesFromTheDefaultStoreViewWithoutATrailingSlash(): void
    {
        $builder = new RenderContextBuilder($this->makeStoreManager('https://muon.localhost/'));

        $context = $builder->build($this->makeRequest(), $this->makeSurface(['Magento_Catalog']));

        self::assertSame('https://muon.localhost', $context->getBaseUrl());
    }

    /**
     * An explicit override wins over the store.
     */
    public function testBaseUrlOverrideWins(): void
    {
        $builder = new RenderContextBuilder($this->makeStoreManager('https://muon.localhost/'));
        $request = new ExportRequest(['Muon'], ['openapi'], 'json', null, 'https://api.example.test/', true, false);

        self::assertSame('https://api.example.test', $builder->build($request, $this->makeSurface())->getBaseUrl());
    }

    /**
     * With no resolvable store the base URL is empty rather than a fatal.
     */
    public function testUnresolvableStoreYieldsAnEmptyBaseUrl(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')
            ->willThrowException(new NoSuchEntityException(__('none')));
        $storeManager->method('getStore')->willThrowException(new NoSuchEntityException(__('none')));

        $builder = new RenderContextBuilder($storeManager);

        self::assertSame('', $builder->build($this->makeRequest(), $this->makeSurface())->getBaseUrl());
    }

    /**
     * A short module list is spelled out in the title.
     */
    public function testShortModuleListIsSpelledOutInTheTitle(): void
    {
        $builder = new RenderContextBuilder($this->makeStoreManager('https://muon.localhost/'));
        $surface = $this->makeSurface(['Magento_Catalog', 'Magento_Sales']);

        self::assertSame(
            'Magento REST API — Magento_Catalog, Magento_Sales',
            $builder->build($this->makeRequest(), $surface)->getTitle()
        );
    }

    /**
     * A long module list is summarised rather than dumped into the title.
     */
    public function testLongModuleListIsSummarised(): void
    {
        $builder = new RenderContextBuilder($this->makeStoreManager('https://muon.localhost/'));
        $surface = $this->makeSurface(['A_One', 'B_Two', 'C_Three', 'D_Four', 'E_Five']);

        self::assertSame(
            'Magento REST API — A_One and 4 more',
            $builder->build($this->makeRequest(), $surface)->getTitle()
        );
    }

    /**
     * With no modules the title falls back to a generic one.
     */
    public function testEmptyModuleListFallsBackToAGenericTitle(): void
    {
        $builder = new RenderContextBuilder($this->makeStoreManager('https://muon.localhost/'));

        self::assertSame(
            'Magento REST API',
            $builder->build($this->makeRequest(), $this->makeSurface([]))->getTitle()
        );
    }

    /**
     * Anything other than an explicit yaml request serialises as JSON.
     */
    public function testSerialisationDefaultsToJson(): void
    {
        $builder = new RenderContextBuilder($this->makeStoreManager('https://muon.localhost/'));

        $json = new ExportRequest(['Muon'], ['openapi'], 'nonsense', null, null, true, false);
        $yaml = new ExportRequest(['Muon'], ['openapi'], 'yaml', null, null, true, false);

        self::assertSame(
            RenderContextInterface::FORMAT_JSON,
            $builder->build($json, $this->makeSurface())->getFormat()
        );
        self::assertSame(
            RenderContextInterface::FORMAT_YAML,
            $builder->build($yaml, $this->makeSurface())->getFormat()
        );
    }

    /**
     * @param string $baseUrl
     * @return \Magento\Store\Model\StoreManagerInterface
     */
    private function makeStoreManager(string $baseUrl): StoreManagerInterface
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);

        return $storeManager;
    }

    /**
     * @return \Muon\ApiSchemaExport\Model\Data\ExportRequest
     */
    private function makeRequest(): ExportRequest
    {
        return new ExportRequest(['Muon'], ['openapi'], 'json', null, null, true, false);
    }

    /**
     * @param string[] $moduleNames
     * @return \Muon\ApiSchemaExport\Model\Data\ApiSurface
     */
    private function makeSurface(array $moduleNames = ['Magento_Catalog']): ApiSurface
    {
        return new ApiSurface([], $moduleNames, []);
    }
}
