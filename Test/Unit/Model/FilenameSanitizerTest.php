<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\ApiSchemaExport\Test\Unit\Model;

use Muon\ApiSchemaExport\Model\FilenameSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @see \Muon\ApiSchemaExport\Model\FilenameSanitizer
 */
class FilenameSanitizerTest extends TestCase
{
    /**
     * @var FilenameSanitizer
     */
    private FilenameSanitizer $sanitizer;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->sanitizer = new FilenameSanitizer();
    }

    /**
     * The base name is the only user-controlled value reaching a path and a download header.
     *
     * @param string|null $input
     * @param string $expected
     * @dataProvider baseNameProvider
     */
    #[DataProvider('baseNameProvider')]
    public function testBaseNameIsReducedToSafeCharacters(?string $input, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer->sanitizeBase($input));
    }

    /**
     * @return array<string,array{0:string|null,1:string}>
     */
    public static function baseNameProvider(): array
    {
        return [
            'posix traversal' => ['../../app/etc/env', 'app-etc-env'],
            'absolute path' => ['/etc/passwd', 'etc-passwd'],
            'windows traversal' => ['..\\..\\windows', 'windows'],
            'empty falls back' => ['', 'api-schema'],
            'null falls back' => [null, 'api-schema'],
            'dots only fall back' => ['...', 'api-schema'],
            'spaces and punctuation' => ['My Export!!', 'My-Export'],
            'already safe' => ['muon-api', 'muon-api'],
        ];
    }

    /**
     * A long name is capped rather than rejected.
     */
    public function testOverlongBaseNameIsCapped(): void
    {
        $result = $this->sanitizer->sanitizeBase(str_repeat('a', 400));

        self::assertSame(120, strlen($result));
    }

    /**
     * Extensions in this module are compound, so dots and underscores must survive.
     */
    public function testCompoundExtensionsSurvive(): void
    {
        self::assertSame(
            'muon.postman_collection.json',
            $this->sanitizer->sanitize('muon', 'postman_collection.json')
        );
        self::assertSame('muon.openapi.yaml', $this->sanitizer->sanitize('muon', 'openapi.yaml'));
    }

    /**
     * An extension cannot smuggle a path segment through.
     */
    public function testExtensionCannotCarryAPath(): void
    {
        self::assertSame('ok-name.evil', $this->sanitizer->sanitize('ok-name', '../evil'));
        self::assertSame('ok-name.etcpasswd', $this->sanitizer->sanitize('ok-name', '/etc/passwd'));
    }

    /**
     * An empty extension yields a bare name rather than a trailing dot.
     */
    public function testEmptyExtensionYieldsBareName(): void
    {
        self::assertSame('muon', $this->sanitizer->sanitize('muon', ''));
    }
}
