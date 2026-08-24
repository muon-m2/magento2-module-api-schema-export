<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Webapi;

use Magento\Webapi\Model\Config as WebapiConfig;
use Magento\Webapi\Model\Config\ClassReflector;
use Magento\Webapi\Model\Config\Converter;
use Muon\ApiSchemaExport\Api\Data\OperationInterface;
use Muon\ApiSchemaExport\Model\Data\Operation;
use Psr\Log\LoggerInterface;

/**
 * Builds operations from Magento's merged REST route table and reflected service metadata.
 *
 * All knowledge of how Magento shapes that data lives here, so the resolver above stays an
 * orchestrator and every renderer downstream sees only this module's own contracts.
 */
class OperationBuilder
{
    /**
     * @param \Magento\Webapi\Model\Config $webapiConfig
     * @param \Magento\Webapi\Model\Config\ClassReflector $classReflector
     * @param \Muon\ApiSchemaExport\Model\Webapi\AsyncRouteDeriver $asyncRouteDeriver
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        private readonly WebapiConfig $webapiConfig,
        private readonly ClassReflector $classReflector,
        private readonly AsyncRouteDeriver $asyncRouteDeriver,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Reflected metadata for service methods already looked up during this run.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $reflected = [];

    /**
     * Build every operation owned by the selected modules, including async variants.
     *
     * @param array<string,string[]> $routeModuleMap Route key to declaring module names.
     * @param string[] $selectedModules
     * @return \Muon\ApiSchemaExport\Api\Data\OperationInterface[]
     */
    public function buildAll(array $routeModuleMap, array $selectedModules): array
    {
        $routes = $this->webapiConfig->getServices()[Converter::KEY_ROUTES] ?? [];

        $operations = [];
        foreach ($routes as $url => $methods) {
            foreach ($methods as $httpMethod => $routeData) {
                $method = strtoupper((string)$httpMethod);
                $declaring = $routeModuleMap[$method . ' ' . $url] ?? [];
                if (array_intersect($declaring, $selectedModules) === []) {
                    continue;
                }

                $operation = $this->build((string)$url, $method, (array)$routeData, $declaring);
                if ($operation === null) {
                    continue;
                }

                $operations[] = $operation;
                foreach ($this->asyncRouteDeriver->derive($operation) as $variant) {
                    $operations[] = $variant;
                }
            }
        }

        return $operations;
    }

    /**
     * Build one synchronous operation.
     *
     * @param string $url
     * @param string $httpMethod
     * @param array<string,mixed> $routeData
     * @param string[] $declaringModules
     * @return \Muon\ApiSchemaExport\Api\Data\OperationInterface|null Null when the route names no service.
     */
    private function build(
        string $url,
        string $httpMethod,
        array $routeData,
        array $declaringModules
    ): ?OperationInterface {
        $serviceClass = (string)($routeData[Converter::KEY_SERVICE][Converter::KEY_SERVICE_CLASS] ?? '');
        $serviceMethod = (string)($routeData[Converter::KEY_SERVICE][Converter::KEY_SERVICE_METHOD] ?? '');
        if ($serviceClass === '' || $serviceMethod === '') {
            return null;
        }

        $methodData = $this->reflectMethod($serviceClass, $serviceMethod);
        $interface = $methodData['interface'] ?? [];

        return new Operation(
            $declaringModules,
            $url,
            $httpMethod,
            $serviceClass,
            $serviceMethod,
            array_keys((array)($routeData[Converter::KEY_ACL_RESOURCES] ?? [])),
            (bool)($routeData[Converter::KEY_SECURE] ?? false),
            OperationInterface::KIND_SYNC,
            (string)($methodData['documentation'] ?? ''),
            (array)($interface['in']['parameters'] ?? []),
            (array)($interface['out']['parameters'] ?? []),
            array_values((array)($interface['out']['throws'] ?? [])),
            (array)($routeData[Converter::KEY_DATA_PARAMETERS] ?? [])
        );
    }

    /**
     * Reflect one service method, memoised per class and method.
     *
     * Deliberately reflects only the methods the selection actually reaches, rather than calling
     * ServiceMetadata::getServicesConfig(), which reflects every service on the installation. That
     * distinction is not a micro-optimisation: reflection throws on a service contract Magento
     * cannot resolve — a bare `array` type with no `@param` item type, for instance — so reflecting
     * everything means one malformed contract anywhere on the install breaks every export, even one
     * that never selected the offending module. Two such methods exist on this installation.
     *
     * A contract that cannot be reflected degrades to empty metadata for that one operation: the
     * route, method, ACL and attribution are all still known, so the endpoint is worth emitting
     * without its parameter schema rather than dropping it.
     *
     * @param string $serviceClass
     * @param string $serviceMethod
     * @return array<string,mixed> Empty when the contract cannot be reflected.
     */
    private function reflectMethod(string $serviceClass, string $serviceMethod): array
    {
        $key = $serviceClass . '::' . $serviceMethod;
        if (array_key_exists($key, $this->reflected)) {
            return $this->reflected[$key];
        }

        $this->reflected[$key] = [];

        try {
            $reflected = $this->classReflector->reflectClassMethods($serviceClass, [$serviceMethod]);
            $this->reflected[$key] = (array)($reflected[$serviceMethod] ?? []);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'Muon_ApiSchemaExport: could not reflect a service method; '
                . 'the endpoint is exported without its parameter schema.',
                ['service' => $key, 'error' => $exception->getMessage()]
            );
        }

        return $this->reflected[$key];
    }
}
