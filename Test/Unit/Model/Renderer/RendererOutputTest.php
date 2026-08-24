<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model\Renderer;

use Muon\ApiSchemaExport\Api\Data\OperationInterface;
use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;
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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies each format's document shape against its own specification.
 */
class RendererOutputTest extends TestCase
{
    use OperationFixtureTrait;

    /**
     * @var DocumentSerializer
     */
    private DocumentSerializer $serializer;
    /**
     * @var RenderContextInterface
     */
    private RenderContextInterface $context;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->serializer = new DocumentSerializer(new Dumper());
        $this->context = new RenderContext(
            'https://muon.localhost',
            'Test API',
            RenderContextInterface::FORMAT_JSON
        );
    }

    /**
     * OpenAPI output declares 3.1.0 and converts Magento's :param form to braces.
     */
    public function testOpenApiDeclaresVersionAndBracedPaths(): void
    {
        $document = json_decode($this->renderOpenApi([$this->makeOperation()]), true);

        self::assertSame('3.1.0', $document['openapi']);
        self::assertArrayHasKey('/rest/V1/products/{sku}', $document['paths']);
        self::assertSame('bearer', $document['components']['securitySchemes']['bearerAuth']['scheme']);
    }

    /**
     * A multi-parameter route converts every placeholder, not just the first.
     */
    public function testEveryPathParameterIsConverted(): void
    {
        $operation = $this->makeOperation(['route' => '/V1/carts/:cartId/items/:itemId']);
        $document = json_decode($this->renderOpenApi([$operation]), true);

        self::assertArrayHasKey('/rest/V1/carts/{cartId}/items/{itemId}', $document['paths']);
    }

    /**
     * Swagger output declares 2.0 and splits the base URL into scheme and host.
     */
    public function testSwaggerDeclaresVersionAndSplitsTheBaseUrl(): void
    {
        $renderer = new SwaggerRenderer(new SchemaBuilder(), $this->serializer);
        $surface = new ApiSurface([$this->makeOperation()], ['Magento_Catalog'], $this->makeTypes());

        $document = json_decode($renderer->render($surface, $this->context), true);

        self::assertSame('2.0', $document['swagger']);
        self::assertSame('muon.localhost', $document['host']);
        self::assertSame(['https'], $document['schemes']);
    }

    /**
     * Swagger 2.0 carries the request body as a parameter rather than a requestBody object.
     */
    public function testSwaggerCarriesTheBodyAsAParameter(): void
    {
        $renderer = new SwaggerRenderer(new SchemaBuilder(), $this->serializer);
        $surface = new ApiSurface([$this->makeOperation()], ['Magento_Catalog'], $this->makeTypes());

        $document = json_decode($renderer->render($surface, $this->context), true);
        $parameters = $document['paths']['/rest/V1/products/{sku}']['put']['parameters'];
        $bodies = array_values(array_filter($parameters, static fn (array $p): bool => $p['in'] === 'body'));

        self::assertCount(1, $bodies);
        self::assertArrayHasKey('schema', $bodies[0]);
    }

    /**
     * A bulk operation's body is an array of the single-item body, in every schema format.
     *
     * Magento's /async/bulk endpoints take an array. The reflected metadata describes one item, so
     * a renderer that emitted the parameters verbatim would produce a document that does not match
     * the running API — and no static check would notice.
     */
    public function testBulkOperationsRenderAnArrayBody(): void
    {
        $bulk = $this->makeOperation([
            'route' => '/async/bulk/V1/products',
            'httpMethod' => 'POST',
            'kind' => OperationInterface::KIND_ASYNC_BULK,
        ]);

        $openApi = json_decode($this->renderOpenApi([$bulk]), true);
        $body = $openApi['paths']['/rest/async/bulk/V1/products']['post']['requestBody'];
        self::assertSame('array', $body['content']['application/json']['schema']['type']);

        $swagger = new SwaggerRenderer(new SchemaBuilder(), $this->serializer);
        $surface = new ApiSurface([$bulk], ['Magento_Catalog'], $this->makeTypes());
        $swaggerDoc = json_decode($swagger->render($surface, $this->context), true);
        $parameters = $swaggerDoc['paths']['/rest/async/bulk/V1/products']['post']['parameters'];
        $bodies = array_values(array_filter($parameters, static fn (array $p): bool => $p['in'] === 'body'));
        self::assertSame('array', $bodies[0]['schema']['type']);
    }

    /**
     * A sync operation's body is not wrapped.
     */
    public function testSyncOperationsRenderAnObjectBody(): void
    {
        $document = json_decode($this->renderOpenApi([$this->makeOperation()]), true);
        $schema = $document['paths']['/rest/V1/products/{sku}']['put']['requestBody']
            ['content']['application/json']['schema'];

        self::assertSame('object', $schema['type']);
    }

    /**
     * Path and forced parameters are never asked of the caller.
     *
     * The sku is already in the URL, and a forced parameter is injected server-side, so a document
     * that listed either as a body field would be wrong.
     */
    public function testPathAndForcedParametersAreExcludedFromTheBody(): void
    {
        $operation = $this->makeOperation([
            'forcedParameters' => ['product' => ['force' => true, 'value' => '%customer_id%']],
        ]);

        $document = json_decode($this->renderOpenApi([$operation]), true);
        $put = $document['paths']['/rest/V1/products/{sku}']['put'];

        // Both declared inputs are excluded, so there is no body at all.
        self::assertArrayNotHasKey('requestBody', $put);
        $names = array_column($put['parameters'], 'name');
        self::assertSame(['sku'], $names);
    }

    /**
     * GET operations expose their inputs as query parameters.
     */
    public function testGetOperationsExposeQueryParameters(): void
    {
        $operation = $this->makeOperation([
            'route' => '/V1/products',
            'httpMethod' => 'GET',
            'inputParameters' => [
                'searchCriteria' => ['type' => 'string', 'required' => false, 'documentation' => ''],
            ],
        ]);

        $document = json_decode($this->renderOpenApi([$operation]), true);
        $parameters = $document['paths']['/rest/V1/products']['get']['parameters'];

        self::assertSame('searchCriteria', $parameters[0]['name']);
        self::assertSame('query', $parameters[0]['in']);
    }

    /**
     * An ACL-protected operation documents its 401 and 403 responses.
     */
    public function testAclProtectedOperationsDocumentAuthResponses(): void
    {
        $document = json_decode($this->renderOpenApi([$this->makeOperation()]), true);
        $responses = $document['paths']['/rest/V1/products/{sku}']['put']['responses'];

        self::assertArrayHasKey('401', $responses);
        self::assertArrayHasKey('403', $responses);
        self::assertArrayHasKey('404', $responses);
    }

    /**
     * The HTTP client file emits one request block per operation, with placeholders only.
     */
    public function testHttpFileEmitsOneBlockPerOperation(): void
    {
        $renderer = new HttpRenderer(new ExampleBuilder(), $this->serializer);
        $surface = new ApiSurface(
            [$this->makeOperation(), $this->makeOperation(['route' => '/V1/products', 'httpMethod' => 'GET'])],
            ['Magento_Catalog'],
            $this->makeTypes()
        );

        $document = $renderer->render($surface, $this->context);

        self::assertSame(2, substr_count($document, '### '));
        self::assertStringContainsString('PUT {{baseUrl}}/rest/V1/products/{{sku}}', $document);
        self::assertStringContainsString('Authorization: Bearer {{token}}', $document);
    }

    /**
     * The Postman collection is valid v2.1 with one folder per module.
     */
    public function testPostmanCollectionShapeIsValid(): void
    {
        $renderer = new PostmanRenderer(new ExampleBuilder(), $this->serializer);
        $surface = new ApiSurface([$this->makeOperation()], ['Magento_Catalog'], $this->makeTypes());

        $collection = json_decode($renderer->render($surface, $this->context), true);

        self::assertStringContainsString('v2.1.0', $collection['info']['schema']);
        self::assertSame('Magento_Catalog', $collection['item'][0]['name']);
        self::assertSame('bearer', $collection['auth']['type']);
        // Postman's own path-variable syntax is the colon form, so the route is kept verbatim.
        self::assertContains(':sku', $collection['item'][0]['item'][0]['request']['url']['path']);
    }

    /**
     * YAML serialisation is opt-in and parses back to the same document.
     */
    public function testYamlSerialisationRoundTrips(): void
    {
        $renderer = new OpenApiRenderer(new SchemaBuilder(), $this->serializer);
        $context = new RenderContext('https://muon.localhost', 'Test API', RenderContextInterface::FORMAT_YAML);
        $surface = new ApiSurface([$this->makeOperation()], ['Magento_Catalog'], $this->makeTypes());

        $parsed = Yaml::parse($renderer->render($surface, $context));

        self::assertSame('3.1.0', $parsed['openapi']);
        self::assertSame('openapi.yaml', $renderer->getFileExtension($context));
    }

    /**
     * Render an OpenAPI document for the given operations.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface[] $operations
     * @return string
     */
    private function renderOpenApi(array $operations): string
    {
        $renderer = new OpenApiRenderer(new SchemaBuilder(), $this->serializer);
        $surface = new ApiSurface($operations, ['Magento_Catalog'], $this->makeTypes());

        return $renderer->render($surface, $this->context);
    }
}
