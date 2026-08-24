<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Serializer;

use Muon\ApiSchemaExport\Api\Data\RenderContextInterface;
use Symfony\Component\Yaml\Dumper;

/**
 * Serialises a structured document as JSON or YAML.
 *
 * Only the OpenAPI and Swagger renderers use this. The HTTP and Postman formats each define their
 * own on-disk representation.
 */
class DocumentSerializer
{
    /**
     * @var \Symfony\Component\Yaml\Dumper
     */
    private Dumper $yamlDumper;

    /**
     * Nesting depth before YAML output collapses to inline flow style.
     */
    private const YAML_INLINE_DEPTH = 12;

    /**
     * Indentation width for YAML output.
     *
     * Set on the dumper itself. Dumper::dump()'s third argument is the initial indentation prefix
     * applied to every line, which is a different thing entirely and produces unparseable output
     * when mistaken for the width.
     */
    private const YAML_INDENT = 2;

    /**
     * @param \Symfony\Component\Yaml\Dumper|null $yamlDumper
     */
    public function __construct(?Dumper $yamlDumper = null)
    {
        $this->yamlDumper = $yamlDumper ?? new Dumper(self::YAML_INDENT);
    }

    /**
     * Serialise a document in the context's requested format.
     *
     * @param array<array-key,mixed> $document
     * @param \Muon\ApiSchemaExport\Api\Data\RenderContextInterface $context
     * @return string
     */
    public function serialize(array $document, RenderContextInterface $context): string
    {
        if ($context->getFormat() === RenderContextInterface::FORMAT_YAML) {
            return $this->yamlDumper->dump($document, self::YAML_INLINE_DEPTH);
        }

        return $this->toJson($document);
    }

    /**
     * Serialise a document as pretty-printed JSON.
     *
     * Slashes stay unescaped so route URLs remain readable in the generated file.
     *
     * @param array<array-key,mixed> $document
     * @return string
     */
    public function toJson(array $document): string
    {
        return (string)json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n";
    }

    /**
     * Get the filename extension matching the context's format.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\RenderContextInterface $context
     * @return string
     */
    public function getExtension(RenderContextInterface $context): string
    {
        return $context->getFormat() === RenderContextInterface::FORMAT_YAML ? 'yaml' : 'json';
    }

    /**
     * Get the MIME type matching the context's format.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\RenderContextInterface $context
     * @return string
     */
    public function getContentType(RenderContextInterface $context): string
    {
        return $context->getFormat() === RenderContextInterface::FORMAT_YAML
            ? 'application/yaml'
            : 'application/json';
    }
}
