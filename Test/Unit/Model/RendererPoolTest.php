<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model;

use Magento\Framework\Exception\InputException;
use Muon\ApiSchemaExport\Api\RendererInterface;
use Muon\ApiSchemaExport\Model\RendererPool;
use PHPUnit\Framework\TestCase;

/**
 * @see \Muon\ApiSchemaExport\Model\RendererPool
 */
class RendererPoolTest extends TestCase
{
    /**
     * Renderers are indexed by their own code, not by the di.xml array key.
     */
    public function testRenderersAreIndexedByTheirOwnCode(): void
    {
        $pool = new RendererPool(['whatever_key' => $this->makeRenderer('openapi')]);

        self::assertSame(['openapi'], $pool->getCodes());
        self::assertSame('openapi', $pool->get('openapi')->getCode());
    }

    /**
     * Codes are sorted so generated output is stable between runs.
     */
    public function testCodesAreSorted(): void
    {
        $pool = new RendererPool([
            $this->makeRenderer('swagger'),
            $this->makeRenderer('http'),
            $this->makeRenderer('openapi'),
        ]);

        self::assertSame(['http', 'openapi', 'swagger'], $pool->getCodes());
    }

    /**
     * An unknown code must throw rather than resolve to anything.
     *
     * Format codes arrive from user input, so this is what keeps a request from reaching a class.
     */
    public function testUnknownCodeThrows(): void
    {
        $pool = new RendererPool([$this->makeRenderer('openapi')]);

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('nope');

        $pool->get('nope');
    }

    /**
     * A non-renderer in the di.xml array is rejected at construction.
     */
    public function testNonRendererEntryIsRejected(): void
    {
        $this->expectException(InputException::class);

        /** @phpstan-ignore-next-line intentionally invalid input */
        new RendererPool(['bad' => new \stdClass()]);
    }

    /**
     * getAll returns the same set, keyed by code.
     */
    public function testGetAllReturnsEveryRendererKeyedByCode(): void
    {
        $pool = new RendererPool([$this->makeRenderer('http'), $this->makeRenderer('postman')]);

        self::assertSame(['http', 'postman'], array_keys($pool->getAll()));
    }

    /**
     * Build a stub renderer reporting the given code.
     *
     * @param string $code
     * @return \Muon\ApiSchemaExport\Api\RendererInterface
     */
    private function makeRenderer(string $code): RendererInterface
    {
        $renderer = $this->createStub(RendererInterface::class);
        $renderer->method('getCode')->willReturn($code);

        return $renderer;
    }
}
