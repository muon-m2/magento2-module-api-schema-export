# Muon_ApiSchemaExport — Developer Guide

## Adding a fifth output format

Implement `Api\RendererInterface` and register it. Nothing in this module changes.

```php
<?php

declare(strict_types=1);

namespace Acme\Docs\Model\Renderer;

use Magento\Framework\Phrase;
use Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface;
use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;
use Muon\ApiSchemaExport\Api\RendererInterface;

class AsciiDocRenderer implements RendererInterface
{
    public function getCode(): string
    {
        return 'asciidoc';
    }

    public function getLabel(): Phrase
    {
        return __('AsciiDoc reference');
    }

    public function getFileExtension(RenderContextInterface $context): string
    {
        return 'adoc';
    }

    public function getContentType(RenderContextInterface $context): string
    {
        return 'text/plain';
    }

    public function render(ApiSurfaceInterface $surface, RenderContextInterface $context): string
    {
        $lines = ['= ' . $context->getTitle(), ''];
        foreach ($surface->getOperations() as $operation) {
            $lines[] = sprintf('== %s %s', $operation->getHttpMethod(), $operation->getRoute());
            $lines[] = $operation->getDescription();
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function getCompanionFiles(
        ApiSurfaceInterface $surface,
        RenderContextInterface $context
    ): array {
        return [];
    }
}
```

```xml
<type name="Muon\ApiSchemaExport\Model\RendererPool">
    <arguments>
        <argument name="renderers" xsi:type="array">
            <item name="asciidoc" xsi:type="object">Acme\Docs\Model\Renderer\AsciiDocRenderer</item>
        </argument>
    </arguments>
</type>
```

`--format=asciidoc` and a new checkbox on the admin screen both work immediately: the pool indexes
renderers by their own `getCode()`, and the admin form lists whatever the pool holds.

### Three things a renderer must get right

1. **Never embed a credential.** Emit a placeholder and, if your format has an environment file,
   ship it empty. These documents are made to be shared.
2. **Convert `:param`.** Magento's route form is `/V1/products/:sku`, which is not valid path syntax
   in any of these formats. Extend `Model\Renderer\AbstractRenderer` and use
   `toPlaceholderPath($route, $open, $close)`.
3. **Wrap the bulk body.** For `OperationInterface::KIND_ASYNC_BULK` the request body is an *array*
   of the described item. `AbstractRenderer::isBodyArray()` tells you; emitting the parameters
   verbatim produces a document that does not match the running API, and nothing static will catch it.

## Amending the resolved surface

`muon_api_schema_export_surface_resolved` fires once per resolve, before type collection.

```xml
<!-- etc/events.xml -->
<event name="muon_api_schema_export_surface_resolved">
    <observer name="acme_hide_internal_routes"
              instance="Acme\Docs\Observer\HideInternalRoutes"/>
</event>
```

```php
public function execute(Observer $observer): void
{
    $transport = $observer->getData('transport');

    $kept = array_filter(
        $transport->getData('operations'),
        static fn (OperationInterface $operation): bool
            => !str_starts_with($operation->getRoute(), '/V1/internal')
    );

    $transport->setData('operations', array_values($kept));
}
```

The array is read back and re-validated, so an entry that is not an `OperationInterface` is
discarded rather than reaching a renderer. Type collection runs **after** the event, so operations
you add still get their schema definitions.

## Calling the exporter from your own code

```php
use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;
use Muon\ApiSchemaExport\Api\ExportManagerInterface;
use Muon\ApiSchemaExport\Model\Data\ExportRequest;

public function __construct(private readonly ExportManagerInterface $exportManager)
{
}

public function exportCatalog(): array
{
    $result = $this->exportManager->export(new ExportRequest(
        ['Magento_Catalog*'],                     // selectors
        ['openapi'],                              // formats
        RenderContextInterface::FORMAT_JSON,      // serialisation
        'catalog-api',                            // base filename, or null to derive
        null,                                     // base URL, or null for the default store view
        true,                                     // include async and bulk variants
        false                                     // one combined file
    ));

    return $result->getFiles();                   // filename => contents
}
```

`ExportRequest`'s constructor carries **no defaults** on purpose: both front-ends resolve every
value explicitly, and a silent default here would let one drift from the other.

`export()` throws `InputException` for an unknown format, a blank selector list, or a selection that
declares no REST routes; and `NoSuchEntityException` when a selector matches no enabled module.

## Reading the surface without rendering

```php
$surface = $this->surfaceResolver->resolve(['Muon']);

foreach ($surface->getOperations() as $operation) {
    // getModuleNames() is plural: webapi.xml merges, so a core route amended by an
    // extension is declared twice and belongs to both.
    printf(
        "%-6s %-45s %s\n",
        $operation->getHttpMethod(),
        $operation->getRoute(),
        implode(', ', $operation->getModuleNames())
    );
}
```

`resolve()` takes no "include async" flag — it always returns the complete surface, and filtering by
`getKind()` is the caller's business. Deciding which kinds reach a document is a presentation
concern, not a resolution one.

## Testing against this module

Double the interfaces, not the implementations — every collaborator has one. `Model\Data\Operation`
takes thirteen constructor arguments, so a fixture helper pays for itself:
see `Test/Unit/OperationFixtureTrait.php`.

Two PHPUnit 12 rules bite here: `@dataProvider` annotations are ignored (use the `#[DataProvider]`
attribute), and a mock with no configured expectation raises a notice — use `createStub()` for
anything you only need to return a value.
