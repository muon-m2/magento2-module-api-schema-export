<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Renderer;

use stdClass;

/**
 * Builds skeleton example payloads from collected type definitions.
 *
 * A generated collection is only useful if its requests carry a body with the right shape, so the
 * request body is materialised from the reflected types rather than left empty.
 *
 * Magento's DTO graph is cyclic in places — a product references categories which reference
 * products — so expansion is bounded by depth and by an in-progress type set. Without both, a
 * single product endpoint would expand forever.
 */
class ExampleBuilder
{
    /**
     * Deepest level of nested objects expanded before a placeholder is emitted.
     */
    private const MAX_DEPTH = 4;

    /**
     * Example values for the scalar types Magento's reflection reports.
     *
     * @var array<string,mixed>
     */
    private const SCALAR_EXAMPLES = [
        'string' => 'string',
        'int' => 0,
        'integer' => 0,
        'float' => 0.0,
        'double' => 0.0,
        'bool' => true,
        'boolean' => true,
        'mixed' => null,
        'anyType' => null,
    ];

    /**
     * Build an example payload for a set of parameters.
     *
     * @param array<string,array<string,mixed>> $parameters
     * @param array<string,mixed> $types Collected type definitions from the surface.
     * @return array<string,mixed>
     */
    public function forParameters(array $parameters, array $types): array
    {
        $example = [];
        foreach ($parameters as $name => $parameter) {
            $type = isset($parameter['type']) ? (string)$parameter['type'] : 'string';
            $example[(string)$name] = $this->forType($type, $types, 0, []);
        }

        return $example;
    }

    /**
     * Build an example value for one declared type.
     *
     * @param string $type
     * @param array<string,mixed> $types
     * @param int $depth
     * @param array<string,bool> $inProgress Types currently being expanded on this branch.
     * @return mixed
     */
    public function forType(string $type, array $types, int $depth = 0, array $inProgress = [])
    {
        if (str_ends_with($type, '[]')) {
            return [$this->forType(substr($type, 0, -2), $types, $depth, $inProgress)];
        }

        $scalar = strtolower($type);
        if (array_key_exists($scalar, self::SCALAR_EXAMPLES)) {
            return self::SCALAR_EXAMPLES[$scalar];
        }

        $definition = $types[$type] ?? null;
        if (!is_array($definition) || $depth >= self::MAX_DEPTH || isset($inProgress[$type])) {
            return new stdClass();
        }

        $inProgress[$type] = true;
        $object = [];
        foreach ((array)($definition['parameters'] ?? []) as $name => $parameter) {
            $memberType = isset($parameter['type']) ? (string)$parameter['type'] : 'string';
            $object[(string)$name] = $this->forType($memberType, $types, $depth + 1, $inProgress);
        }

        return $object === [] ? new stdClass() : $object;
    }
}
