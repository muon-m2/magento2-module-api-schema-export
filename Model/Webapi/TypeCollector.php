<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Webapi;

use Magento\Framework\Reflection\TypeProcessor;
use Muon\ApiSchemaExport\Api\Data\OperationInterface;

/**
 * Collects the reflected type definitions reachable from a set of operations.
 *
 * Magento's type processor holds definitions for the whole installation once service metadata has
 * been warmed. Emitting all of them would bury a two-module export under several thousand
 * irrelevant schemas, so the reachable set is walked from the selected operations' own parameters
 * instead: seed with the types they name, then follow each definition's own parameter types until
 * the closure is complete.
 */
class TypeCollector
{
    /**
     * @param \Magento\Framework\Reflection\TypeProcessor $typeProcessor
     */
    public function __construct(private readonly TypeProcessor $typeProcessor)
    {
    }

    /**
     * Collect every type definition reachable from the given operations.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface[] $operations
     * @return array<string,mixed> Keyed by the type processor's own type name.
     */
    public function collect(array $operations): array
    {
        return $this->walk($this->seed($operations), $this->typeProcessor->getTypesData());
    }

    /**
     * Collect the type names directly named by the operations' own parameters.
     *
     * @param \Muon\ApiSchemaExport\Api\Data\OperationInterface[] $operations
     * @return string[]
     */
    private function seed(array $operations): array
    {
        $seeds = [];
        foreach ($operations as $operation) {
            $parameterSets = [$operation->getInputParameters(), $operation->getOutputParameters()];
            foreach ($parameterSets as $parameters) {
                foreach ($parameters as $parameter) {
                    $name = $this->normalise(isset($parameter['type']) ? (string)$parameter['type'] : null);
                    if ($name !== null) {
                        $seeds[] = $name;
                    }
                }
            }
        }

        return $seeds;
    }

    /**
     * Walk the seeded type names, following each definition's own parameter types.
     *
     * @param string[] $queue
     * @param array<string,mixed> $all Every definition the type processor holds.
     * @return array<string,mixed>
     */
    private function walk(array $queue, array $all): array
    {
        $collected = [];

        while ($queue !== []) {
            $typeName = array_pop($queue);
            if (array_key_exists($typeName, $collected) || !array_key_exists($typeName, $all)) {
                continue;
            }

            $definition = $all[$typeName];
            $collected[$typeName] = $definition;

            foreach ((array)($definition['parameters'] ?? []) as $parameter) {
                $next = $this->normalise(isset($parameter['type']) ? (string)$parameter['type'] : null);
                if ($next !== null && !array_key_exists($next, $collected)) {
                    $queue[] = $next;
                }
            }
        }

        ksort($collected);

        return $collected;
    }

    /**
     * Reduce a declared type to the name its definition is stored under.
     *
     * The reflected metadata already carries translated names — ClassReflector stores whatever
     * TypeProcessor::register() returned, which is the translated form. Translating again throws
     * "The %s parameter type is invalid", because translateTypeName() expects a fully-qualified
     * class name and an already-translated name has no namespace left to match. So this only
     * strips the array suffix and filters out the types that have no definition to collect.
     *
     * @param string|null $type
     * @return string|null Null when the type has no collectable definition.
     */
    private function normalise(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        $itemType = str_ends_with($type, '[]') ? substr($type, 0, -2) : $type;
        if ($itemType === '') {
            return null;
        }

        if ($this->typeProcessor->isTypeSimple($itemType) || $this->typeProcessor->isTypeAny($itemType)) {
            return null;
        }

        return $itemType;
    }
}
