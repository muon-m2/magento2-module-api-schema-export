<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model\Renderer;

use Muon\ApiSchemaExport\Model\Renderer\SchemaBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @see \Muon\ApiSchemaExport\Model\Renderer\SchemaBuilder
 */
class SchemaBuilderTest extends TestCase
{
    private const REF_ROOT = '#/components/schemas/';

    /**
     * @var SchemaBuilder
     */
    private SchemaBuilder $schemaBuilder;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->schemaBuilder = new SchemaBuilder();
    }

    /**
     * @param string $type
     * @param array<string,mixed> $expected
     * @dataProvider typeProvider
     */
    #[DataProvider('typeProvider')]
    public function testTypesMapToJsonSchema(string $type, array $expected): void
    {
        self::assertSame($expected, $this->schemaBuilder->forType($type, self::REF_ROOT));
    }

    /**
     * @return array<string,array{0:string,1:array<string,mixed>}>
     */
    public static function typeProvider(): array
    {
        return [
            'string' => ['string', ['type' => 'string']],
            'int' => ['int', ['type' => 'integer']],
            'float' => ['float', ['type' => 'number']],
            'bool' => ['bool', ['type' => 'boolean']],
            'scalar array' => ['string[]', ['type' => 'array', 'items' => ['type' => 'string']]],
            'complex' => [
                'CatalogDataProductInterface',
                ['$ref' => self::REF_ROOT . 'CatalogDataProductInterface'],
            ],
            'complex array' => [
                'CatalogDataProductInterface[]',
                [
                    'type' => 'array',
                    'items' => ['$ref' => self::REF_ROOT . 'CatalogDataProductInterface'],
                ],
            ],
        ];
    }

    /**
     * An untyped value must not serialise as [], which both specifications reject.
     *
     * @param string $type
     * @dataProvider untypedProvider
     */
    #[DataProvider('untypedProvider')]
    public function testUntypedValueSerialisesAsAnObject(string $type): void
    {
        $schema = $this->schemaBuilder->forType($type, self::REF_ROOT);

        self::assertNotSame([], $schema);
        self::assertSame('{"description":"Any type."}', json_encode($schema));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function untypedProvider(): array
    {
        return ['mixed' => ['mixed'], 'anyType' => ['anyType']];
    }

    /**
     * Required members are listed, and documentation reaches the schema.
     */
    public function testParametersBecomeAnObjectSchema(): void
    {
        $schema = $this->schemaBuilder->forParameters(
            [
                'sku' => ['type' => 'string', 'required' => true, 'documentation' => 'The SKU.'],
                'price' => ['type' => 'float', 'required' => false, 'documentation' => ''],
            ],
            self::REF_ROOT
        );

        self::assertSame('object', $schema['type']);
        self::assertSame(['sku'], $schema['required']);
        self::assertSame('The SKU.', $schema['properties']['sku']['description']);
        self::assertSame(['type' => 'number'], $schema['properties']['price']);
    }

    /**
     * An empty properties map must still encode as a JSON object.
     */
    public function testEmptyPropertiesEncodeAsAnObject(): void
    {
        $schema = $this->schemaBuilder->forParameters([], self::REF_ROOT);

        self::assertStringContainsString('"properties":{}', (string)json_encode($schema));
    }

    /**
     * asMap keeps a map an object even when nothing is in it.
     */
    public function testAsMapKeepsEmptyMapsAsObjects(): void
    {
        self::assertSame('{}', json_encode($this->schemaBuilder->asMap([])));
        self::assertSame('{"a":1}', json_encode($this->schemaBuilder->asMap(['a' => 1])));
    }

    /**
     * Collected type definitions become named schemas carrying their documentation.
     */
    public function testTypesBecomeNamedDefinitions(): void
    {
        $definitions = $this->schemaBuilder->forTypes(
            [
                'ProductInterface' => [
                    'documentation' => 'A product.',
                    'parameters' => ['sku' => ['type' => 'string', 'required' => true]],
                ],
                'NotAnArray' => 'ignored',
            ],
            self::REF_ROOT
        );

        self::assertArrayHasKey('ProductInterface', $definitions);
        self::assertArrayNotHasKey('NotAnArray', $definitions);
        self::assertSame('A product.', $definitions['ProductInterface']['description']);
    }
}
