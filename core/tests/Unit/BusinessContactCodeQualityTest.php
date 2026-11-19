<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BusinessContactCodeQualityTest extends TestCase
{
    private const MAX_LINE_LENGTH = 160;

    #[DataProvider('sourceFileProvider')]
    public function test_business_contact_source_remains_readable(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines, "Unable to read {$path}.");

        foreach ($lines as $lineNumber => $line) {
            $this->assertLessThanOrEqual(
                self::MAX_LINE_LENGTH,
                mb_strlen($line),
                sprintf(
                    'Business Contact source line is too long: %s:%d',
                    self::relativePath($path),
                    $lineNumber + 1,
                ),
            );
        }
    }

    #[DataProvider('bladeFileProvider')]
    public function test_business_contact_blades_do_not_use_compressed_markup(string $path): void
    {
        $patterns = [
            '~</(?:div|form|table|thead|tbody|tr|ul|section|th)>\s*'
                .'<(?:div|form|table|thead|tbody|tr|ul|section|th)\b~i',
            '~@csrf\s+@method~',
            '~<script>\s*var~',
            '~@(?:if|foreach)\s*\([^\r\n]+\)\s*<~',
        ];
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines, "Unable to read {$path}.");

        foreach ($lines as $lineNumber => $line) {
            foreach ($patterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $line,
                    sprintf(
                        'Business Contact Blade markup is compressed: %s:%d',
                        self::relativePath($path),
                        $lineNumber + 1,
                    ),
                );
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function sourceFileProvider(): iterable
    {
        foreach (self::sourceFiles() as $path) {
            yield self::relativePath($path) => [$path];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function bladeFileProvider(): iterable
    {
        foreach (self::bladeFiles() as $path) {
            yield self::relativePath($path) => [$path];
        }
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        return [
            self::projectRoot().'/app/Http/Controllers/backend/CustomerController.php',
            self::projectRoot().'/app/Http/Controllers/backend/SupplierController.php',
            self::projectRoot().'/app/Models/backend/Customer.php',
            self::projectRoot().'/app/Models/backend/Supplier.php',
            ...self::bladeFiles(),
        ];
    }

    /** @return list<string> */
    private static function bladeFiles(): array
    {
        return [
            self::projectRoot().'/resources/views/backend/modules/customers/index.blade.php',
            self::projectRoot().'/resources/views/backend/modules/customers/modal.blade.php',
            self::projectRoot().'/resources/views/backend/modules/suppliers/index.blade.php',
            self::projectRoot().'/resources/views/backend/modules/suppliers/modal.blade.php',
        ];
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function relativePath(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(self::projectRoot()) + 1));
    }
}
