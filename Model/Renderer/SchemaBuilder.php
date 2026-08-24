<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Renderer;

use stdClass;

/**
 * Converts Magento's reflected type data into JSON Schema fragments.
 *
 * OpenAPI 3.1 and Swagger 2.0 describe the same types but root their references differently, so
 * the reference root is supplied per call rather than baked in. Everything else about the schema
 * shape is identical between the two, which is why this lives in one place.
 */
class SchemaBuilder
{
    /**
     * Reflected scalar type names mapped to their JSON Schema equivalents.
     *
     * @var array<string,string>
     */
    private const SCALAR_TYPES = [
        'string' => 'string',
        'int' => 'integer',
        'integer' => 'integer',
        'float' => 'number',
        'double' => 'number',
        'bool' => 'boolean',
        'boolean' => 'boolean',
    ];

    /**
     * Build the schema fragment for one declared type.
     *
     * @param string $type
     * @param string $refRoot Reference prefix, e.g. "#/components/schemas/".
     * @return array<string,mixed>
     */
    public function forType(string $type, string $refRoot): array
    {
        if (str_ends_with($type, '[]')) {
            return [
                'type' => 'array',
                'items' => $this->forType(substr($type, 0, -2), $refRoot),
            ];
        }

        $scalar = strtolower($type);
        if (isset(self::SCALAR_TYPES[$scalar])) {
            return ['type' => self::SCALAR_TYPES[$scalar]];
        }

        // An untyped value carries no constraints. An empty PHP array would serialise as [], and
        // both specifications require a Schema Object here, so the constraint-free schema is
        // expressed with a description instead of emptiness.
        if ($scalar === 'mixed' || $scalar === 'anytype') {
            return ['description' => 'Any type.'];
        }

        return ['$ref' => $refRoot . $type];
    }

    /**
     * Build an object schema from a set of parameters.
     *
     * @param array<string,array<string,mixed>> $parameters
     * @param string $refRoot
     * @return array<string,mixed>
     */
    public function forParameters(array $parameters, string $refRoot): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $name => $parameter) {
            $name = (string)$name;
            $schema = $this->forType(isset($parameter['type']) ? (string)$parameter['type'] : 'string', $refRoot);
            $documentation = trim((string)($parameter['documentation'] ?? ''));
            if ($documentation !== '' && !isset($schema['$ref'])) {
                $schema['description'] = $documentation;
            }
            $properties[$name] = $schema;

            if (!empty($parameter['required'])) {
                $required[] = $name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $this->asMap($properties)];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Keep a map serialising as a JSON object even when it is empty.
     *
     * An empty PHP array is encoded by json_encode as [], which is a valid JSON array and an
     * invalid Schema Object. Every position that must stay an object passes through here.
     *
     * @param array<string,mixed> $value
     * @return array<string,mixed>|\stdClass
     */
    public function asMap(array $value)
    {
        return $value === [] ? new stdClass() : $value;
    }

    /**
     * Build the full definition map for a surface's collected types.
     *
     * @param array<string,mixed> $types
     * @param string $refRoot
     * @return array<string,mixed>
     */
    public function forTypes(array $types, string $refRoot): array
    {
        $definitions = [];

        foreach ($types as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $schema = $this->forParameters(
                (array)($definition['parameters'] ?? []),
                $refRoot
            );
            $documentation = trim((string)($definition['documentation'] ?? ''));
            if ($documentation !== '') {
                $schema['description'] = $documentation;
            }
            $definitions[(string)$name] = $schema;
        }

        return $definitions;
    }
}
