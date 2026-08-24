<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model\Renderer;

use Muon\ApiSchemaExport\Model\Renderer\ExampleBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @see \Muon\ApiSchemaExport\Model\Renderer\ExampleBuilder
 */
class ExampleBuilderTest extends TestCase
{
    /**
     * @var ExampleBuilder
     */
    private ExampleBuilder $exampleBuilder;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->exampleBuilder = new ExampleBuilder();
    }

    /**
     * Scalars get a representative value of the right PHP type.
     */
    public function testScalarsGetRepresentativeValues(): void
    {
        self::assertSame('string', $this->exampleBuilder->forType('string', []));
        self::assertSame(0, $this->exampleBuilder->forType('int', []));
        self::assertSame(0.0, $this->exampleBuilder->forType('float', []));
        self::assertTrue($this->exampleBuilder->forType('bool', []));
    }

    /**
     * An array type produces a single-element list of its item type.
     */
    public function testArrayTypeProducesASingleElementList(): void
    {
        self::assertSame(['string'], $this->exampleBuilder->forType('string[]', []));
    }

    /**
     * A complex type expands into its members.
     */
    public function testComplexTypeExpandsIntoItsMembers(): void
    {
        $types = [
            'ProductInterface' => [
                'parameters' => [
                    'sku' => ['type' => 'string'],
                    'price' => ['type' => 'float'],
                ],
            ],
        ];

        self::assertSame(
            ['sku' => 'string', 'price' => 0.0],
            $this->exampleBuilder->forType('ProductInterface', $types)
        );
    }

    /**
     * Magento's DTO graph is cyclic; expansion must terminate rather than recurse forever.
     *
     * A product references categories which reference products. Without the in-progress guard a
     * single product endpoint would never finish rendering.
     */
    public function testMutuallyRecursiveTypesTerminate(): void
    {
        $types = [
            'A' => ['parameters' => ['id' => ['type' => 'int'], 'b' => ['type' => 'B']]],
            'B' => ['parameters' => ['a' => ['type' => 'A']]],
        ];

        $example = $this->exampleBuilder->forParameters(['entity' => ['type' => 'A']], $types);

        self::assertSame(0, $example['entity']['id']);
        // The cycle is cut with an empty object rather than expanded again.
        self::assertInstanceOf(\stdClass::class, $example['entity']['b']['a']);
    }

    /**
     * A self-referential type terminates too.
     */
    public function testSelfReferentialTypeTerminates(): void
    {
        $types = ['Node' => ['parameters' => ['child' => ['type' => 'Node']]]];

        $example = $this->exampleBuilder->forType('Node', $types);

        self::assertInstanceOf(\stdClass::class, $example['child']);
    }

    /**
     * An unknown type yields an empty object rather than failing.
     */
    public function testUnknownTypeYieldsAnEmptyObject(): void
    {
        self::assertInstanceOf(\stdClass::class, $this->exampleBuilder->forType('NoSuchType', []));
    }

    /**
     * Deep nesting is bounded, so a long chain still finishes.
     */
    public function testDeepNestingIsBounded(): void
    {
        $types = [];
        foreach (range(1, 10) as $level) {
            $types['L' . $level] = ['parameters' => ['next' => ['type' => 'L' . ($level + 1)]]];
        }

        $example = $this->exampleBuilder->forType('L1', $types);

        self::assertIsArray($example);
        self::assertArrayHasKey('next', $example);
    }
}
