<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model\Webapi;

use DOMDocument;
use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Module\Dir\Reader;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps every declared REST route back to the module or modules that declare it.
 *
 * Magento merges every module's etc/webapi.xml into one route table and, in doing so, discards
 * which module each route came from. Magento\Webapi\Model\Config therefore cannot answer "which
 * routes does this module own", which is the whole premise of a per-module export. The declaring
 * module is recovered here by reading the same files individually: Dir\Reader hands back a path
 * for every webapi.xml it merged, and each path resolves to a module through the component
 * registrar.
 *
 * Because Dir\Reader only walks enabled modules, disabled modules are excluded structurally rather
 * than by a filter that could drift.
 */
class RouteModuleMap
{
    /**
     * Cache key prefix. The enabled-module set is folded into the suffix, and the entry is tagged
     * with the config cache tag so a config flush clears it alongside everything else config-derived.
     */
    private const CACHE_ID_PREFIX = 'muon_api_schema_export_route_module_map_';

    /**
     * @var array<string,string[]>|null
     */
    private ?array $map = null;

    /**
     * @param \Magento\Framework\Module\Dir\Reader $moduleReader
     * @param \Magento\Framework\Component\ComponentRegistrarInterface $componentRegistrar
     * @param \Magento\Framework\Module\ModuleListInterface $moduleList
     * @param \Magento\Framework\App\CacheInterface $cache
     * @param \Magento\Framework\Serialize\SerializerInterface $serializer
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        private readonly Reader $moduleReader,
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly ModuleListInterface $moduleList,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get the route-to-module map.
     *
     * Keys are "{HTTP_METHOD} {url}", values are every module declaring that exact pair. The value
     * is a list rather than a single name because webapi.xml merges: a core route amended by an
     * extension is declared twice, and a selector matching either module must find the operation.
     *
     * @return array<string,string[]>
     */
    public function get(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $cacheId = self::CACHE_ID_PREFIX . $this->getModuleSetHash();
        $cached = $this->cache->load($cacheId);
        if (is_string($cached) && $cached !== '') {
            $decoded = $this->serializer->unserialize($cached);
            if (is_array($decoded)) {
                $this->map = $this->normalise($decoded);
                return $this->map;
            }
        }

        $this->map = $this->build();
        $this->cache->save(
            (string)$this->serializer->serialize($this->map),
            $cacheId,
            [ConfigCacheType::CACHE_TAG]
        );

        return $this->map;
    }

    /**
     * Coerce a decoded cache payload back into the map's declared shape.
     *
     * The cache round-trip loses static type information, and a stale or hand-edited entry could
     * hold anything, so the payload is re-shaped rather than trusted.
     *
     * @param array<mixed> $decoded
     * @return array<string,string[]>
     */
    private function normalise(array $decoded): array
    {
        $map = [];
        foreach ($decoded as $routeKey => $modules) {
            if (!is_string($routeKey) || !is_array($modules)) {
                continue;
            }
            $names = array_values(array_filter($modules, 'is_string'));
            if ($names !== []) {
                $map[$routeKey] = $names;
            }
        }

        return $map;
    }

    /**
     * Build the map by parsing each module's own webapi.xml.
     *
     * @return array<string,string[]>
     */
    private function build(): array
    {
        $modulePaths = $this->getSortedModulePaths();
        $map = [];

        foreach ($this->moduleReader->getConfigurationFiles('webapi.xml')->toArray() as $path => $contents) {
            $moduleName = $this->resolveModuleName((string)$path, $modulePaths);
            if ($moduleName === null) {
                $this->logger->warning(
                    'Muon_ApiSchemaExport: could not attribute a webapi.xml to a module.',
                    ['path' => $path]
                );
                continue;
            }

            foreach ($this->extractRoutes((string)$path, (string)$contents) as $routeKey) {
                $map[$routeKey][] = $moduleName;
            }
        }

        foreach ($map as $routeKey => $modules) {
            $map[$routeKey] = array_values(array_unique($modules));
        }

        return $map;
    }

    /**
     * Get registered module paths, normalised and ordered longest first.
     *
     * Ordering matters: one module's directory can be a string prefix of another's, so
     * Muon_Cart would otherwise claim Muon_CartRecalculation's files. Each path is given a
     * trailing separator so matching happens on directory boundaries, not raw string prefixes.
     *
     * @return array<string,string>
     */
    private function getSortedModulePaths(): array
    {
        $paths = [];
        foreach ($this->componentRegistrar->getPaths(ComponentRegistrar::MODULE) as $name => $path) {
            $paths[$name] = rtrim(str_replace('\\', '/', (string)$path), '/') . '/';
        }

        uasort($paths, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $paths;
    }

    /**
     * Resolve a configuration file path to the module that owns it.
     *
     * @param string $path
     * @param array<string,string> $modulePaths Ordered longest path first.
     * @return string|null
     */
    private function resolveModuleName(string $path, array $modulePaths): ?string
    {
        $normalised = str_replace('\\', '/', $path);
        foreach ($modulePaths as $moduleName => $modulePath) {
            if (str_starts_with($normalised, $modulePath)) {
                return $moduleName;
            }
        }

        return null;
    }

    /**
     * Extract "{METHOD} {url}" keys from one webapi.xml document.
     *
     * A malformed file is skipped and logged rather than aborting the export: Magento's merged
     * config still carries the route, so the only loss is attribution for that one file.
     *
     * @param string $path
     * @param string $contents
     * @return string[]
     */
    private function extractRoutes(string $path, string $contents): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($contents, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            $this->logger->warning(
                'Muon_ApiSchemaExport: skipping malformed webapi.xml.',
                ['path' => $path, 'error' => $errors[0]->message ?? 'unknown']
            );
            return [];
        }

        $keys = [];
        foreach ($document->getElementsByTagName('route') as $route) {
            $url = trim((string)$route->getAttribute('url'));
            $method = strtoupper(trim((string)$route->getAttribute('method')));
            if ($url === '' || $method === '') {
                continue;
            }
            $keys[] = $method . ' ' . $url;
        }

        return $keys;
    }

    /**
     * Get a stable hash of the enabled-module set, used as the cache key suffix.
     *
     * @return string
     */
    private function getModuleSetHash(): string
    {
        $names = $this->moduleList->getNames();
        sort($names);

        return hash('sha256', implode(',', $names));
    }
}
