<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model\Renderer;

use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;
use Muon\ApiSchemaExport\Api\RendererInterface;
use Muon\ApiSchemaExport\Model\Data\ApiSurface;
use Muon\ApiSchemaExport\Model\Data\RenderContext;
use Muon\ApiSchemaExport\Model\Renderer\ExampleBuilder;
use Muon\ApiSchemaExport\Model\Renderer\HttpRenderer;
use Muon\ApiSchemaExport\Model\Renderer\OpenApiRenderer;
use Muon\ApiSchemaExport\Model\Renderer\PostmanRenderer;
use Muon\ApiSchemaExport\Model\Renderer\SchemaBuilder;
use Muon\ApiSchemaExport\Model\Renderer\SwaggerRenderer;
use Muon\ApiSchemaExport\Model\Serializer\DocumentSerializer;
use Muon\ApiSchemaExport\Test\Unit\OperationFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Dumper;

/**
 * Every renderer must produce artifacts that are safe to share.
 *
 * These documents are made to be handed to integrators and committed to repositories. A renderer
 * that helpfully embedded a live token would turn each one into a credential leak, and nothing in
 * the type system would catch it — so it is asserted here, for every format, on every run.
 */
class RendererSecretFreeTest extends TestCase
{
    use OperationFixtureTrait;

    /**
     * Values that must never appear in generated output.
     */
    private const FORBIDDEN = [
        'sekrit-admin-token-value',
        'admin@example.test',
        'hunter2',
    ];

    /**
     * @param string $rendererClass
     * @return void
     * @dataProvider rendererProvider
     */
    #[DataProvider('rendererProvider')]
    public function testOutputCarriesNoCredential(string $rendererClass): void
    {
        $renderer = $this->makeRenderer($rendererClass);
        $context = new RenderContext('https://muon.localhost', 'Test', RenderContextInterface::FORMAT_JSON);
        $surface = new ApiSurface([$this->makeOperation()], ['Magento_Catalog'], $this->makeTypes());

        $documents = array_merge(
            [$renderer->render($surface, $context)],
            array_values($renderer->getCompanionFiles($surface, $context))
        );

        foreach ($documents as $document) {
            foreach (self::FORBIDDEN as $secret) {
                self::assertStringNotContainsString($secret, $document);
            }
        }
    }

    /**
     * The token placeholder must be present and its value empty wherever a value is carried.
     *
     * @param string $rendererClass
     * @return void
     * @dataProvider companionProvider
     */
    #[DataProvider('companionProvider')]
    public function testCompanionEnvironmentFilesShipEmptyTokens(string $rendererClass): void
    {
        $renderer = $this->makeRenderer($rendererClass);
        $context = new RenderContext('https://muon.localhost', 'Test', RenderContextInterface::FORMAT_JSON);
        $surface = new ApiSurface([$this->makeOperation()], ['Magento_Catalog'], $this->makeTypes());

        $companions = $renderer->getCompanionFiles($surface, $context);
        self::assertNotEmpty($companions, 'This renderer is expected to emit a companion file.');

        foreach ($companions as $contents) {
            $decoded = json_decode($contents, true);
            self::assertIsArray($decoded, 'Companion file must be valid JSON.');
            self::assertSame('', $this->findTokenValue($decoded), 'The token must ship empty.');
        }
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function rendererProvider(): array
    {
        return [
            'http' => [HttpRenderer::class],
            'postman' => [PostmanRenderer::class],
            'openapi' => [OpenApiRenderer::class],
            'swagger' => [SwaggerRenderer::class],
        ];
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function companionProvider(): array
    {
        return [
            'http' => [HttpRenderer::class],
            'postman' => [PostmanRenderer::class],
        ];
    }

    /**
     * Find the token value anywhere in a decoded companion file.
     *
     * @param array<array-key,mixed> $decoded
     * @return string|null
     */
    private function findTokenValue(array $decoded): ?string
    {
        foreach ($decoded as $key => $value) {
            if ($key === 'token' && is_string($value)) {
                return $value;
            }
            if (is_array($value)) {
                if (($value['key'] ?? null) === 'token') {
                    return (string)($value['value'] ?? null);
                }
                $found = $this->findTokenValue($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Build a renderer with its real collaborators.
     *
     * An explicit match rather than dynamic instantiation: each branch is typed, so a renderer
     * that stopped implementing the contract would fail here rather than at an assertion.
     *
     * @param string $rendererClass
     * @return \Muon\ApiSchemaExport\Api\RendererInterface
     */
    private function makeRenderer(string $rendererClass): RendererInterface
    {
        $serializer = new DocumentSerializer(new Dumper());

        return match ($rendererClass) {
            OpenApiRenderer::class => new OpenApiRenderer(new SchemaBuilder(), $serializer),
            SwaggerRenderer::class => new SwaggerRenderer(new SchemaBuilder(), $serializer),
            PostmanRenderer::class => new PostmanRenderer(new ExampleBuilder(), $serializer),
            default => new HttpRenderer(new ExampleBuilder(), $serializer),
        };
    }
}
