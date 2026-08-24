<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Renderer;

use Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface;
use Muon\ApiSchemaExport\Api\Data\OperationInterface;
use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;
use Muon\ApiSchemaExport\Api\RendererInterface;

/**
 * Shared operation handling for every output format.
 *
 * Each concrete renderer owns its document envelope. Everything that would otherwise be
 * reimplemented four times — path-placeholder conversion, splitting inputs into path, query and
 * body, operation identifiers, module grouping — lives here, so the four formats cannot drift
 * apart in how they read an operation.
 *
 * Authentication is represented by placeholders in every format. No renderer may embed a real
 * token, admin credential or integration key: these documents are made to be shared.
 */
abstract class AbstractRenderer implements RendererInterface
{
    /**
     * Placeholder variable for the API root.
     */
    protected const VAR_BASE_URL = 'baseUrl';

    /**
     * Placeholder variable for the bearer token. Always emitted empty.
     */
    protected const VAR_TOKEN = 'token';

    /**
     * @inheritDoc
     */
    public function getCompanionFiles(
        ApiSurfaceInterface $surface,
        RenderContextInterface $context
    ): array {
        return [];
    }

    /**
     * Extract the path-parameter names from a Magento route.
     *
     * @param string $route
     * @return string[]
     */
    protected function getPathParameters(string $route): array
    {
        $names = [];
        foreach (explode('/', $route) as $segment) {
            if (str_starts_with($segment, ':') && strlen($segment) > 1) {
                $names[] = substr($segment, 1);
            }
        }

        return $names;
    }

    /**
     * Rewrite a Magento route, replacing each :param with a delimited placeholder.
     *
     * Magento's own :param form is not valid path syntax in OpenAPI, Swagger or an HTTP client
     * file, so every renderer converts it. Postman is the exception and keeps the colon form.
     *
     * @param string $route
     * @param string $open
     * @param string $close
     * @return string
     */
    protected function toPlaceholderPath(string $route, string $open, string $close): string
    {
        $segments = [];
        foreach (explode('/', $route) as $segment) {
            $segments[] = str_starts_with($segment, ':') && strlen($segment) > 1
                ? $open . substr($segment, 1) . $close
                : $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Build a stable, unique identifier for an operation.
     *
     * Derived from the HTTP method and route rather than the service method, because several
     * routes can share one service method: POST /V1/products and PUT /V1/products/:sku both call
     * save(). Method plus route is the pair that is actually unique.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface $operation
     * @return string
     */
    protected function getOperationId(OperationInterface $operation): string
    {
        $slug = strtolower($operation->getRoute());
        $slug = (string)preg_replace('/[^a-z0-9]+/', '_', $slug);

        return strtolower($operation->getHttpMethod()) . '_' . trim($slug, '_');
    }

    /**
     * Build a one-line summary for an operation.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface $operation
     * @return string
     */
    protected function getSummary(OperationInterface $operation): string
    {
        $description = trim($operation->getDescription());
        if ($description !== '') {
            $firstLine = strtok($description, "\n");
            return $firstLine === false ? $description : trim($firstLine);
        }

        return $operation->getHttpMethod() . ' ' . $operation->getRoute();
    }

    /**
     * Whether the operation's request body is an array of the described item.
     *
     * True only for the bulk asynchronous variant. See OperationInterface::KIND_ASYNC_BULK.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface $operation
     * @return bool
     */
    protected function isBodyArray(OperationInterface $operation): bool
    {
        return $operation->getKind() === OperationInterface::KIND_ASYNC_BULK;
    }

    /**
     * Whether the operation carries a request body at all.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface $operation
     * @return bool
     */
    protected function hasBody(OperationInterface $operation): bool
    {
        return $operation->getHttpMethod() !== 'GET' && $this->getCallerParameters($operation) !== [];
    }

    /**
     * Get the parameters a caller actually supplies.
     *
     * Two groups are removed. Path parameters already appear in the URL, and forced parameters
     * are injected server-side from the request context, so a document that asked a caller to
     * supply either would be wrong.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface $operation
     * @return array<string,array<string,mixed>>
     */
    protected function getCallerParameters(OperationInterface $operation): array
    {
        $excluded = array_merge(
            $this->getPathParameters($operation->getRoute()),
            array_keys($operation->getForcedParameters())
        );

        return array_diff_key($operation->getInputParameters(), array_flip($excluded));
    }

    /**
     * Group a surface's operations by the selected modules that declare them.
     *
     * An operation declared by two modules appears under each of them that was selected, which is
     * what keeps a per-module split honest.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\ApiSurfaceInterface $surface
     * @return array<string,\Muon\ApiSchemaExport\Api\Data\OperationInterface[]>
     */
    protected function groupByModule(ApiSurfaceInterface $surface): array
    {
        $selected = $surface->getModuleNames();
        $grouped = [];

        foreach ($surface->getOperations() as $operation) {
            $owners = array_intersect($operation->getModuleNames(), $selected);
            if ($owners === []) {
                $owners = ['Other'];
            }
            foreach ($owners as $owner) {
                $grouped[$owner][] = $operation;
            }
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * Get the ACL resources an operation requires, as a display string.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface $operation
     * @return string
     */
    protected function getAclSummary(OperationInterface $operation): string
    {
        $resources = $operation->getAclResources();

        return $resources === [] ? 'anonymous' : implode(', ', $resources);
    }
}
