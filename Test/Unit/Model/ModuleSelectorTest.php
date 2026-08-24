<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\ModuleListInterface;
use Muon\ApiSchemaExport\Model\ModuleSelector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @see \Muon\ApiSchemaExport\Model\ModuleSelector
 */
class ModuleSelectorTest extends TestCase
{
    private const MODULES = [
        'Magento_Catalog',
        'Magento_CatalogInventory',
        'Magento_Sales',
        'Muon_FileAttachment',
        'Muon_CustomerPrice',
    ];

    /**
     * @var ModuleSelector
     */
    private ModuleSelector $selector;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $moduleList = $this->createStub(ModuleListInterface::class);
        $moduleList->method('getNames')->willReturn(self::MODULES);

        $this->selector = new ModuleSelector($moduleList);
    }

    /**
     * @param string[] $selectors
     * @param string[] $expected
     * @dataProvider selectorProvider
     */
    #[DataProvider('selectorProvider')]
    public function testSelectorFormsResolveCorrectly(array $selectors, array $expected): void
    {
        self::assertSame($expected, $this->selector->match($selectors));
    }

    /**
     * @return array<string,array{0:string[],1:string[]}>
     */
    public static function selectorProvider(): array
    {
        return [
            'exact' => [['Muon_FileAttachment'], ['Muon_FileAttachment']],
            'namespace' => [['Muon'], ['Muon_CustomerPrice', 'Muon_FileAttachment']],
            'wildcard' => [
                ['Magento_Catalog*'],
                ['Magento_Catalog', 'Magento_CatalogInventory'],
            ],
            'everything' => [
                ['*'],
                [
                    'Magento_Catalog',
                    'Magento_CatalogInventory',
                    'Magento_Sales',
                    'Muon_CustomerPrice',
                    'Muon_FileAttachment',
                ],
            ],
            'several, de-duplicated' => [
                ['Muon_FileAttachment', 'Muon'],
                ['Muon_CustomerPrice', 'Muon_FileAttachment'],
            ],
            'blank entries ignored alongside a real one' => [
                ['', 'Muon_FileAttachment', '  '],
                ['Muon_FileAttachment'],
            ],
        ];
    }

    /**
     * A namespace selector must not match a module whose name merely starts with those letters.
     */
    public function testNamespaceSelectorMatchesOnTheUnderscoreBoundary(): void
    {
        $moduleList = $this->createStub(ModuleListInterface::class);
        $moduleList->method('getNames')->willReturn(['Muon_Cart', 'MuonExtra_Cart']);
        $selector = new ModuleSelector($moduleList);

        self::assertSame(['Muon_Cart'], $selector->match(['Muon']));
    }

    /**
     * An unmatched selector names itself in the exception.
     */
    public function testUnmatchedSelectorThrows(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Nope_Nope');

        $this->selector->match(['Nope_Nope']);
    }

    /**
     * One good selector does not excuse a bad one.
     */
    public function testAnyUnmatchedSelectorThrowsEvenAlongsideAMatch(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->selector->match(['Muon', 'Nope_Nope']);
    }

    /**
     * An empty or all-blank list must fail rather than silently resolve to nothing.
     *
     * Returning an empty array here produced a valid, endpoint-free document that every front-end
     * reported as success.
     *
     * @param string[] $selectors
     * @dataProvider emptySelectorProvider
     */
    #[DataProvider('emptySelectorProvider')]
    public function testEmptySelectorListThrows(array $selectors): void
    {
        $this->expectException(InputException::class);

        $this->selector->match($selectors);
    }

    /**
     * @return array<string,array{0:string[]}>
     */
    public static function emptySelectorProvider(): array
    {
        return [
            'empty array' => [[]],
            'blank string' => [['']],
            'whitespace only' => [['   ']],
        ];
    }
}
