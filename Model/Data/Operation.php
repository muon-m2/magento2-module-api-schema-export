<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Data;

use Muon\ApiSchemaExport\Api\Data\OperationInterface;

/**
 * Immutable record of one REST operation.
 *
 * Thirteen constructor parameters is a lot, but every one of them is a distinct, independently
 * meaningful field of the operation, and collapsing them into an array bag would trade explicit
 * types for a shapeless payload the renderers would then have to guess at.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
class Operation implements OperationInterface
{
    /**
     * @param string[] $moduleNames
     * @param string $route
     * @param string $httpMethod
     * @param string $serviceClass
     * @param string $serviceMethod
     * @param string[] $aclResources
     * @param bool $secure
     * @param string $kind
     * @param string $description
     * @param array<string,array<string,mixed>> $inputParameters
     * @param array<string,array<string,mixed>> $outputParameters
     * @param string[] $thrownExceptions
     * @param array<string,array<string,mixed>> $forcedParameters
     */
    public function __construct(
        private readonly array $moduleNames,
        private readonly string $route,
        private readonly string $httpMethod,
        private readonly string $serviceClass,
        private readonly string $serviceMethod,
        private readonly array $aclResources,
        private readonly bool $secure,
        private readonly string $kind,
        private readonly string $description,
        private readonly array $inputParameters,
        private readonly array $outputParameters,
        private readonly array $thrownExceptions,
        private readonly array $forcedParameters
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getModuleNames(): array
    {
        return $this->moduleNames;
    }

    /**
     * @inheritDoc
     */
    public function getRoute(): string
    {
        return $this->route;
    }

    /**
     * @inheritDoc
     */
    public function getHttpMethod(): string
    {
        return $this->httpMethod;
    }

    /**
     * @inheritDoc
     */
    public function getServiceClass(): string
    {
        return $this->serviceClass;
    }

    /**
     * @inheritDoc
     */
    public function getServiceMethod(): string
    {
        return $this->serviceMethod;
    }

    /**
     * @inheritDoc
     */
    public function getAclResources(): array
    {
        return $this->aclResources;
    }

    /**
     * @inheritDoc
     */
    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * @inheritDoc
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @inheritDoc
     */
    public function getInputParameters(): array
    {
        return $this->inputParameters;
    }

    /**
     * @inheritDoc
     */
    public function getOutputParameters(): array
    {
        return $this->outputParameters;
    }

    /**
     * @inheritDoc
     */
    public function getThrownExceptions(): array
    {
        return $this->thrownExceptions;
    }

    /**
     * @inheritDoc
     */
    public function getForcedParameters(): array
    {
        return $this->forcedParameters;
    }
}
