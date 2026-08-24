<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model\Webapi;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Config\FileIterator;
use Magento\Framework\Module\Dir\Reader;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Muon\ApiSchemaExport\Model\Webapi\RouteModuleMap;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @see \Muon\ApiSchemaExport\Model\Webapi\RouteModuleMap
 */
class RouteModuleMapTest extends TestCase
{
    private const ROOT = '/var/www/magento/vendor/magento';

    /**
     * A route resolves to the module whose directory contains its webapi.xml.
     */
    public function testRoutesAreAttributedToTheirDeclaringModule(): void
    {
        $map = $this->makeMap(
            ['Magento_Catalog' => self::ROOT . '/module-catalog'],
            [
                self::ROOT . '/module-catalog/etc/webapi.xml' => $this->routesXml([
                    ['/V1/products', 'POST'],
                    ['/V1/products/:sku', 'GET'],
                ]),
            ]
        )->get();

        self::assertSame(['Magento_Catalog'], $map['POST /V1/products']);
        self::assertSame(['Magento_Catalog'], $map['GET /V1/products/:sku']);
    }

    /**
     * A route declared by two modules is attributed to both.
     *
     * webapi.xml merges, so a core route amended by an extension is declared twice. Attributing it
     * to one module would silently drop it from the other's export.
     */
    public function testARouteDeclaredTwiceKeepsBothOwners(): void
    {
        $map = $this->makeMap(
            [
                'Magento_Catalog' => self::ROOT . '/module-catalog',
                'Muon_CatalogExtra' => '/var/www/magento/app/code/Muon/CatalogExtra',
            ],
            [
                self::ROOT . '/module-catalog/etc/webapi.xml' => $this->routesXml([['/V1/products', 'POST']]),
                '/var/www/magento/app/code/Muon/CatalogExtra/etc/webapi.xml'
                    => $this->routesXml([['/V1/products', 'POST']]),
            ]
        )->get();

        self::assertSame(['Magento_Catalog', 'Muon_CatalogExtra'], $map['POST /V1/products']);
    }

    /**
     * One module's directory can be a string prefix of another's.
     *
     * Matching on a raw string prefix would let Muon_Cart claim every file belonging to
     * Muon_CartRecalculation. Matching happens on the directory boundary instead.
     */
    public function testPrefixCollidingModuleDirectoriesDoNotStealEachOthersFiles(): void
    {
        $base = '/var/www/magento/app/code/Muon';
        $map = $this->makeMap(
            [
                'Muon_Cart' => $base . '/Cart',
                'Muon_CartRecalculation' => $base . '/CartRecalculation',
            ],
            [
                $base . '/Cart/etc/webapi.xml' => $this->routesXml([['/V1/cart', 'GET']]),
                $base . '/CartRecalculation/etc/webapi.xml' => $this->routesXml([['/V1/recalc', 'GET']]),
            ]
        )->get();

        self::assertSame(['Muon_Cart'], $map['GET /V1/cart']);
        self::assertSame(['Muon_CartRecalculation'], $map['GET /V1/recalc']);
    }

    /**
     * HTTP methods are normalised so lookup is case-insensitive on the method.
     */
    public function testHttpMethodsAreUppercased(): void
    {
        $map = $this->makeMap(
            ['Magento_Catalog' => self::ROOT . '/module-catalog'],
            [
                self::ROOT . '/module-catalog/etc/webapi.xml' => $this->routesXml([['/V1/products', 'post']]),
            ]
        )->get();

        self::assertArrayHasKey('POST /V1/products', $map);
    }

    /**
     * A malformed file is skipped and logged rather than aborting the whole export.
     */
    public function testMalformedFileIsSkippedAndLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $map = $this->makeMap(
            ['Magento_Catalog' => self::ROOT . '/module-catalog'],
            [self::ROOT . '/module-catalog/etc/webapi.xml' => '<routes><route url='],
            $logger
        )->get();

        self::assertSame([], $map);
    }

    /**
     * A file that belongs to no registered module is reported, not silently dropped.
     */
    public function testUnattributableFileIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $map = $this->makeMap(
            ['Magento_Catalog' => self::ROOT . '/module-catalog'],
            ['/somewhere/else/etc/webapi.xml' => $this->routesXml([['/V1/x', 'GET']])],
            $logger
        )->get();

        self::assertSame([], $map);
    }

    /**
     * A cached map is returned without re-reading any file.
     */
    public function testCachedMapShortCircuitsFileReading(): void
    {
        $reader = $this->createMock(Reader::class);
        $reader->expects(self::never())->method('getConfigurationFiles');

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn('serialized');

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('unserialize')->willReturn(['GET /V1/x' => ['Magento_Catalog']]);

        $moduleList = $this->createStub(ModuleListInterface::class);
        $moduleList->method('getNames')->willReturn(['Magento_Catalog']);

        $registrar = $this->createStub(ComponentRegistrarInterface::class);

        $map = new RouteModuleMap(
            $reader,
            $registrar,
            $moduleList,
            $cache,
            $serializer,
            $this->createStub(LoggerInterface::class)
        );

        self::assertSame(['GET /V1/x' => ['Magento_Catalog']], $map->get());
    }

    /**
     * A corrupt cache payload is re-shaped rather than trusted.
     */
    public function testCorruptCachePayloadIsDiscarded(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn('serialized');

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('unserialize')->willReturn(['GET /V1/x' => 'not-an-array', 5 => ['X']]);

        $moduleList = $this->createStub(ModuleListInterface::class);
        $moduleList->method('getNames')->willReturn(['Magento_Catalog']);

        $map = new RouteModuleMap(
            $this->createStub(Reader::class),
            $this->createStub(ComponentRegistrarInterface::class),
            $moduleList,
            $cache,
            $serializer,
            $this->createStub(LoggerInterface::class)
        );

        self::assertSame([], $map->get());
    }

    /**
     * Build a map over the given module paths and webapi.xml contents.
     *
     * @param array<string,string> $modulePaths
     * @param array<string,string> $files
     * @param \Psr\Log\LoggerInterface|null $logger
     * @return \Muon\ApiSchemaExport\Model\Webapi\RouteModuleMap
     */
    private function makeMap(array $modulePaths, array $files, ?LoggerInterface $logger = null): RouteModuleMap
    {
        $iterator = $this->createStub(FileIterator::class);
        $iterator->method('toArray')->willReturn($files);

        $reader = $this->createStub(Reader::class);
        $reader->method('getConfigurationFiles')->willReturn($iterator);

        $registrar = $this->createStub(ComponentRegistrarInterface::class);
        $registrar->method('getPaths')->willReturn($modulePaths);

        $moduleList = $this->createStub(ModuleListInterface::class);
        $moduleList->method('getNames')->willReturn(array_keys($modulePaths));

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturn('');

        return new RouteModuleMap(
            $reader,
            $registrar,
            $moduleList,
            $cache,
            $serializer,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * Build a webapi.xml document declaring the given routes.
     *
     * @param array<int,array{0:string,1:string}> $routes
     * @return string
     */
    private function routesXml(array $routes): string
    {
        $nodes = '';
        foreach ($routes as [$url, $method]) {
            $nodes .= sprintf(
                '<route url="%s" method="%s"><service class="X" method="y"/></route>',
                $url,
                $method
            );
        }

        return '<?xml version="1.0"?><routes>' . $nodes . '</routes>';
    }
}
