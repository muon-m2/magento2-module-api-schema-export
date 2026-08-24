<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Model;

/**
 * Reduces a user-supplied base filename to something safe to write and to name in a download.
 *
 * This is the only user-controlled value in the feature that reaches both a filesystem path and a
 * Content-Disposition header, so it is normalised at the boundary rather than trusted anywhere
 * downstream.
 */
class FilenameSanitizer
{
    /**
     * Longest base name kept, before the extension is appended.
     */
    private const MAX_LENGTH = 120;

    /**
     * Used when sanitising leaves nothing usable.
     */
    private const FALLBACK = 'api-schema';

    /**
     * Sanitise a base name and append an extension.
     *
     * @param string|null $baseName
     * @param string $extension Extension without a leading dot.
     * @return string
     */
    public function sanitize(?string $baseName, string $extension): string
    {
        $safe = $this->sanitizeBase($baseName);

        // Extensions are compound in this module — postman_collection.json, openapi.yaml — so dots
        // and underscores survive. Separators and traversal segments still do not.
        $suffix = preg_replace('/[^A-Za-z0-9._]/', '', $extension) ?? '';
        $suffix = trim((string)preg_replace('/\.{2,}/', '.', $suffix), '.');

        return $suffix === '' ? $safe : $safe . '.' . strtolower($suffix);
    }

    /**
     * Sanitise a base name without appending an extension.
     *
     * Every directory separator is removed rather than escaped, so no input can describe a path:
     * "../../app/etc/env" collapses to "app-etc-env". Leading dots are stripped too, so the result
     * can never be a dotfile or a traversal segment.
     *
     * @param string|null $baseName
     * @return string
     */
    public function sanitizeBase(?string $baseName): string
    {
        $candidate = str_replace(['/', '\\'], '-', (string)$baseName);
        $candidate = preg_replace('/[^A-Za-z0-9._-]/', '-', $candidate) ?? '';
        $candidate = preg_replace('/-{2,}/', '-', $candidate) ?? '';
        $candidate = trim($candidate, '.-');

        if (strlen($candidate) > self::MAX_LENGTH) {
            $candidate = rtrim(substr($candidate, 0, self::MAX_LENGTH), '.-');
        }

        return $candidate === '' ? self::FALLBACK : $candidate;
    }
}
