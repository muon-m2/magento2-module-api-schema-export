<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model;

use Magento\Framework\Exception\InputException;
use Muon\ApiSchemaExport\Api\RendererInterface;
use Muon\ApiSchemaExport\Api\RendererPoolInterface;

/**
 * Registry of output formats, populated by a di.xml array argument.
 *
 * This is the module's primary extension point: a new format is one class implementing
 * RendererInterface plus one node in di.xml, with no change here.
 */
class RendererPool implements RendererPoolInterface
{
    /**
     * @var array<string,\Muon\ApiSchemaExport\Api\RendererInterface>
     */
    private array $renderers;

    /**
     * @param \Muon\ApiSchemaExport\Api\RendererInterface[] $renderers
     * @throws \Magento\Framework\Exception\InputException When a registered entry is not a renderer.
     */
    public function __construct(array $renderers = [])
    {
        $indexed = [];
        foreach ($renderers as $key => $renderer) {
            if (!$renderer instanceof RendererInterface) {
                throw new InputException(
                    __('Renderer "%1" must implement RendererInterface.', (string)$key)
                );
            }
            $indexed[$renderer->getCode()] = $renderer;
        }

        ksort($indexed);
        $this->renderers = $indexed;
    }

    /**
     * @inheritDoc
     */
    public function get(string $code): RendererInterface
    {
        if (!isset($this->renderers[$code])) {
            throw new InputException(
                __('Unknown output format "%1". Available: %2.', $code, implode(', ', $this->getCodes()))
            );
        }

        return $this->renderers[$code];
    }

    /**
     * @inheritDoc
     */
    public function getCodes(): array
    {
        return array_keys($this->renderers);
    }

    /**
     * @inheritDoc
     */
    public function getAll(): array
    {
        return $this->renderers;
    }
}
