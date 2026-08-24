<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Api\Data;

/**
 * Per-render inputs shared by every renderer.
 *
 * @api
 */
interface RenderContextInterface
{
    /**
     * Structured documents serialized as JSON.
     */
    public const FORMAT_JSON = 'json';

    /**
     * Structured documents serialized as YAML.
     */
    public const FORMAT_YAML = 'yaml';

    /**
     * Get the base URL that generated requests should target, without a trailing slash.
     *
     * @return string
     */
    public function getBaseUrl(): string;

    /**
     * Get the human-readable title for the generated document.
     *
     * @return string
     */
    public function getTitle(): string;

    /**
     * Get the serialization format for structured documents.
     *
     * Applies to the OpenAPI and Swagger renderers only. The HTTP and Postman renderers define
     * their own on-disk format and ignore this value.
     *
     * @return string One of the FORMAT_* constants on this interface.
     */
    public function getFormat(): string;
}
