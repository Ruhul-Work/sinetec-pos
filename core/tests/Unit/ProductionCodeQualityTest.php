<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ProductionCodeQualityTest extends TestCase
{
    private const MAX_LINE_LENGTH = 160;

    #[DataProvider('productionSourceFileProvider')]
    public function test_production_source_lines_remain_readable(string $path): void
    {
        foreach ($this->readLines($path) as $lineNumber => $line) {
            $this->assertLessThanOrEqual(
                self::MAX_LINE_LENGTH,
                mb_strlen($line),
                $this->failureMessage($path, $lineNumber, 'Line exceeds the Production readability limit.'),
            );
        }
    }

    #[DataProvider('productionBladeFileProvider')]
    public function test_production_blades_do_not_use_minified_structural_markup(string $path): void
    {
        $compressedPatterns = [
            '~</(?:div|form|table|thead|tbody|tr|ul|section|th)>\s*'
                .'<(?:div|form|table|thead|tbody|tr|ul|section|th)\b~i',
            '~@csrf\s+@method~',
            '~<script>\s*var~',
            '~@(?:if|foreach)\s*\([^\r\n]+\)\s*<~',
        ];

        foreach ($this->readLines($path) as $lineNumber => $line) {
            foreach ($compressedPatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $line,
                    $this->failureMessage($path, $lineNumber, 'Structural markup is compressed.'),
                );
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function productionSourceFileProvider(): iterable
    {
        foreach (self::productionPhpFiles() as $path) {
            yield self::relativePath($path) => [$path];
        }

        foreach (self::productionBladeFiles() as $path) {
            yield self::relativePath($path) => [$path];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function productionBladeFileProvider(): iterable
    {
        foreach (self::productionBladeFiles() as $path) {
            yield self::relativePath($path) => [$path];
        }
    }

    /** @return list<string> */
    private static function productionPhpFiles(): array
    {
        $root = self::projectRoot();
        $files = [
            $root.'/app/Http/Controllers/backend/PartProductionController.php',
            $root.'/app/Http/Controllers/backend/ProductionExpenseCategoryController.php',
            $root.'/app/Services/Production/PartProductionService.php',
        ];

        foreach ([
            $root.'/app/Enums/Production',
            $root.'/app/Exceptions/Production',
        ] as $directory) {
            array_push($files, ...self::phpFilesIn($directory));
        }

        foreach (self::phpFilesIn($root.'/app/Models/Production') as $path) {
            if (str_contains(basename($path), 'PartProduction')
                || basename($path) === 'ProductionExpenseCategory.php') {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private static function productionBladeFiles(): array
    {
        $files = self::phpFilesIn(
            self::projectRoot().'/resources/views/backend/modules/part_production',
        );
        array_push(
            $files,
            ...self::phpFilesIn(
                self::projectRoot().'/resources/views/backend/modules/production_expense_category',
            ),
        );
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private static function phpFilesIn(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return array<int, string> */
    private function readLines(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        $this->assertIsArray($lines, "Unable to read {$path}.");

        return $lines;
    }

    private function failureMessage(string $path, int $lineNumber, string $message): string
    {
        return sprintf('%s %s:%d', $message, self::relativePath($path), $lineNumber + 1);
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
