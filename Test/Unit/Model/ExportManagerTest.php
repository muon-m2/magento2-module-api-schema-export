<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model;

use Magento\Framework\Exception\InputException;
use Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface;
use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;
use Muon\ApiSchemaExport\Api\RendererInterface;
use Muon\ApiSchemaExport\Api\RendererPoolInterface;
use Muon\ApiSchemaExport\Api\SurfaceResolverInterface;
use Muon\ApiSchemaExport\Model\Data\ApiSurface;
use Muon\ApiSchemaExport\Model\Data\ExportRequest;
use Muon\ApiSchemaExport\Model\Data\RenderContext;
use Muon\ApiSchemaExport\Model\Export\RenderContextBuilder;
use Muon\ApiSchemaExport\Model\Export\SurfaceSplitter;
use Muon\ApiSchemaExport\Model\ExportManager;
use Muon\ApiSchemaExport\Model\FilenameSanitizer;
use Muon\ApiSchemaExport\Test\Unit\OperationFixtureTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @see \Muon\ApiSchemaExport\Model\ExportManager
 */
class ExportManagerTest extends TestCase
{
    use OperationFixtureTrait;

    /**
     * @var MockObject&SurfaceResolverInterface
     */
    private MockObject $surfaceResolver;

    /**
     * @var MockObject&SurfaceSplitter
     */
    private MockObject $surfaceSplitter;

    /**
     * @var ExportManager
     */
    private ExportManager $exportManager;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->surfaceResolver = $this->createMock(SurfaceResolverInterface::class);
        $this->surfaceSplitter = $this->createMock(SurfaceSplitter::class);

        $contextBuilder = $this->createStub(RenderContextBuilder::class);
        $contextBuilder->method('build')->willReturn(
            new RenderContext('https://muon.localhost', 'Test', RenderContextInterface::FORMAT_JSON)
        );

        $this->exportManager = new ExportManager(
            $this->surfaceResolver,
            $this->makePool(),
            $contextBuilder,
            $this->surfaceSplitter,
            new FilenameSanitizer()
        );
    }

    /**
     * A single format yields the document plus whatever companion the renderer emits.
     */
    public function testSingleFormatProducesDocumentAndCompanion(): void
    {
        $this->surfaceResolver->expects(self::once())->method('resolve')->willReturn($this->makeSurface());
        $this->surfaceSplitter->expects(self::never())->method('withoutAsync');

        $result = $this->exportManager->export($this->makeRequest(['openapi']));

        self::assertSame(
            ['magento_catalog.openapi.json', 'magento_catalog.env.json'],
            array_keys($result->getFiles())
        );
        self::assertSame(2, $result->getFileCount());
        // The resolved base name travels with the result so every delivery path names alike.
        self::assertSame('magento_catalog', $result->getBaseName());
    }

    /**
     * The base filename is derived from a single-module selection.
     */
    public function testFilenameIsDerivedFromASingleModuleSelection(): void
    {
        $this->surfaceResolver->expects(self::once())->method('resolve')->willReturn($this->makeSurface());
        $this->surfaceSplitter->expects(self::never())->method('withoutAsync');

        $result = $this->exportManager->export($this->makeRequest(['openapi']));

        self::assertArrayHasKey('magento_catalog.openapi.json', $result->getFiles());
    }

    /**
     * A supplied filename is sanitised before it reaches a path or a header.
     */
    public function testSuppliedFilenameIsSanitised(): void
    {
        $this->surfaceResolver->expects(self::once())->method('resolve')->willReturn($this->makeSurface());
        $this->surfaceSplitter->expects(self::never())->method('withoutAsync');

        $result = $this->exportManager->export(
            new ExportRequest(['Magento_Catalog'], ['openapi'], 'json', '../../etc/passwd', null, true, false)
        );

        self::assertArrayHasKey('etc-passwd.openapi.json', $result->getFiles());
    }

    /**
     * Dropping async delegates to the splitter rather than filtering inline.
     */
    public function testExcludingAsyncDelegatesToTheSplitter(): void
    {
        $surface = $this->makeSurface();
        $this->surfaceResolver->expects(self::once())->method('resolve')->willReturn($surface);
        $this->surfaceSplitter->expects(self::once())->method('withoutAsync')->willReturn($surface);

        $this->exportManager->export(
            new ExportRequest(['Magento_Catalog'], ['openapi'], 'json', null, null, false, false)
        );
    }

    /**
     * Splitting produces one document per module, each named for it.
     */
    public function testSplittingProducesOneFilePerModule(): void
    {
        $surface = $this->makeSurface(['Magento_Catalog', 'Magento_Sales']);
        $this->surfaceResolver->expects(self::once())->method('resolve')->willReturn($surface);
        $this->surfaceSplitter->expects(self::never())->method('withoutAsync');
        $this->surfaceSplitter->expects(self::once())->method('byModule')->willReturn([
            'Magento_Catalog' => $this->makeSurface(['Magento_Catalog']),
            'Magento_Sales' => $this->makeSurface(['Magento_Sales']),
        ]);

        $result = $this->exportManager->export(
            new ExportRequest(['Muon'], ['openapi'], 'json', 'api', null, true, true)
        );

        self::assertArrayHasKey('api-Magento_Catalog.openapi.json', $result->getFiles());
        self::assertArrayHasKey('api-Magento_Sales.openapi.json', $result->getFiles());
    }

    /**
     * A module with no REST routes must fail rather than yield an empty document.
     *
     * A valid, endpoint-free file reads as success in every front-end, which is worse than an error.
     */
    public function testAnEmptySurfaceIsRejected(): void
    {
        $this->surfaceResolver->expects(self::once())->method('resolve')->willReturn(
            new ApiSurface([], ['Muon_ProductDetail'], [])
        );
        $this->surfaceSplitter->expects(self::never())->method('withoutAsync');

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Muon_ProductDetail');

        $this->exportManager->export($this->makeRequest(['openapi']));
    }

    /**
     * An unknown format is rejected by the pool before anything is rendered.
     */
    public function testUnknownFormatIsRejected(): void
    {
        $this->surfaceResolver->expects(self::once())->method('resolve')->willReturn($this->makeSurface());
        $this->surfaceSplitter->expects(self::never())->method('withoutAsync');

        $this->expectException(InputException::class);

        $this->exportManager->export($this->makeRequest(['nope']));
    }

    /**
     * Build a request for the given formats.
     *
     * @param string[] $formats
     * @return \Muon\ApiSchemaExport\Model\Data\ExportRequest
     */
    private function makeRequest(array $formats): ExportRequest
    {
        return new ExportRequest(['Magento_Catalog'], $formats, 'json', null, null, true, false);
    }

    /**
     * Build a surface holding one operation.
     *
     * @param string[] $moduleNames
     * @return \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface
     */
    private function makeSurface(array $moduleNames = ['Magento_Catalog']): ApiSurfaceInterface
    {
        return new ApiSurface([$this->makeOperation()], $moduleNames, $this->makeTypes());
    }

    /**
     * Build a pool holding one renderer that emits a document and a companion.
     *
     * @return \Muon\ApiSchemaExport\Api\RendererPoolInterface
     */
    private function makePool(): RendererPoolInterface
    {
        $renderer = $this->createStub(RendererInterface::class);
        $renderer->method('getCode')->willReturn('openapi');
        $renderer->method('getFileExtension')->willReturn('openapi.json');
        $renderer->method('getContentType')->willReturn('application/json');
        $renderer->method('render')->willReturn('{"openapi":"3.1.0"}');
        $renderer->method('getCompanionFiles')->willReturn(['env.json' => '{}']);

        $pool = $this->createStub(RendererPoolInterface::class);
        $pool->method('getCodes')->willReturn(['openapi']);
        $pool->method('get')->willReturnCallback(
            static function (string $code) use ($renderer): RendererInterface {
                if ($code !== 'openapi') {
                    throw new InputException(__('Unknown output format "%1".', $code));
                }
                return $renderer;
            }
        );

        return $pool;
    }
}
