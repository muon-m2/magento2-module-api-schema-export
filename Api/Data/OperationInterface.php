<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Api\Data;

/**
 * A single REST operation, resolved back to the module or modules that declare it.
 *
 * Magento merges every module's etc/webapi.xml into one route table and discards the declaring
 * module in the process. This DTO restores that attribution, which is what makes a per-module
 * export possible at all.
 *
 * @api
 */
interface OperationInterface
{
    /**
     * Operation declared directly in a module's etc/webapi.xml.
     */
    public const KIND_SYNC = 'sync';

    /**
     * Single-entity asynchronous variant served under /async/V1.
     */
    public const KIND_ASYNC = 'async';

    /**
     * Bulk asynchronous variant served under /async/bulk/V1.
     *
     * The request body of a bulk operation is an ARRAY of the body the synchronous operation
     * takes. getInputParameters() still describes a single item, because that is the unit the
     * reflected service metadata describes; wrapping it in an array is the renderer's job. A
     * renderer that emits the input parameters verbatim for this kind produces a document that
     * does not match the running API.
     */
    public const KIND_ASYNC_BULK = 'async_bulk';

    /**
     * Get every module that declares this route and HTTP method.
     *
     * Plural because webapi.xml merges: a core route amended by an extension is declared twice,
     * and a selector matching either module must include the operation.
     *
     * @return string[]
     */
    public function getModuleNames(): array;

    /**
     * Get the route URL in Magento's own form, with :param placeholders intact.
     *
     * @return string
     */
    public function getRoute(): string;

    /**
     * Get the uppercase HTTP method.
     *
     * @return string
     */
    public function getHttpMethod(): string;

    /**
     * Get the fully qualified service interface backing this route.
     *
     * @return string
     */
    public function getServiceClass(): string;

    /**
     * Get the service method backing this route.
     *
     * @return string
     */
    public function getServiceMethod(): string;

    /**
     * Get the ACL resources a caller must hold to invoke this operation.
     *
     * @return string[]
     */
    public function getAclResources(): array;

    /**
     * Whether the route is declared secure, meaning HTTPS is required.
     *
     * @return bool
     */
    public function isSecure(): bool;

    /**
     * Get the operation kind.
     *
     * @return string One of the KIND_* constants on this interface.
     */
    public function getKind(): string;

    /**
     * Get the operation description, taken from the service method's docblock summary.
     *
     * @return string Empty string when the service method carries no documentation.
     */
    public function getDescription(): string;

    /**
     * Get the reflected input parameters for the backing service method.
     *
     * Keyed by parameter name, each entry carrying type, required and documentation, exactly as
     * Magento's reflected service metadata holds it. Query parameters for GET routes and
     * request-body fields for every other method are both derived from this list.
     *
     * For KIND_ASYNC_BULK operations this describes a single item; the actual request body is an
     * array of them. See the KIND_ASYNC_BULK constant.
     *
     * @return array<string,array<string,mixed>>
     */
    public function getInputParameters(): array;

    /**
     * Get the reflected output parameters for the backing service method.
     *
     * Keyed by name, with Magento using the single key "result" for a method's return value.
     *
     * @return array<string,array<string,mixed>>
     */
    public function getOutputParameters(): array;

    /**
     * Get the exception classes the backing service method declares it throws.
     *
     * Renderers map these onto error responses.
     *
     * @return string[]
     */
    public function getThrownExceptions(): array;

    /**
     * Get parameters the route forces or sources from the request context.
     *
     * Declared as <parameters> in webapi.xml, e.g. a customer id bound to %customer_id%. These must
     * never appear as caller-supplied fields in a generated document.
     *
     * @return array<string,array<string,mixed>>
     */
    public function getForcedParameters(): array;
}
