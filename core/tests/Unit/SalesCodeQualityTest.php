<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SalesCodeQualityTest extends TestCase
{
    private const MAX_LINE_LENGTH = 160;

    #[DataProvider('salesSourceFileProvider')]
    public function test_sales_source_lines_remain_readable(string $path): void
    {
        foreach ($this->readLines($path) as $lineNumber => $line) {
            $this->assertLessThanOrEqual(
                self::MAX_LINE_LENGTH,
                mb_strlen($line),
                $this->failureMessage($path, $lineNumber, 'Line exceeds the Sales readability limit.'),
            );
        }
    }

    #[DataProvider('salesBladeFileProvider')]
    public function test_sales_blades_do_not_use_minified_structural_markup(string $path): void
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
    public static function salesSourceFileProvider(): iterable
    {
        foreach (self::salesPhpFiles() as $path) {
            yield self::relativePath($path) => [$path];
        }

        foreach (self::salesBladeFiles() as $path) {
            yield self::relativePath($path) => [$path];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function salesBladeFileProvider(): iterable
    {
        foreach (self::salesBladeFiles() as $path) {
            yield self::relativePath($path) => [$path];
        }
    }

    /** @return list<string> */
    private static function salesPhpFiles(): array
    {
        $root = self::projectRoot();
        $files = [
            $root.'/app/Http/Controllers/backend/PosSaleController.php',
            $root.'/app/Http/Controllers/backend/WholesaleSaleController.php',
            $root.'/app/Http/Controllers/backend/CustomerReceivableController.php',
            $root.'/app/Http/Controllers/backend/SaleReturnController.php',
            $root.'/app/Services/Finance/SaleReturnJournalService.php',
            $root.'/app/Services/Finance/SalesJournalService.php',
            $root.'/app/Services/Sales/SaleDraftService.php',
            $root.'/app/Services/Sales/SaleCheckoutService.php',
            $root.'/app/Services/Sales/CustomerReceiptService.php',
            $root.'/app/Services/Sales/SaleReturnService.php',
        ];

        foreach ([
            $root.'/app/Enums/Sales',
            $root.'/app/Exceptions/Sales',
            $root.'/app/Models/Sales',
        ] as $directory) {
            array_push($files, ...self::phpFilesIn($directory));
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private static function salesBladeFiles(): array
    {
        $files = [];
        foreach (['pos_sale', 'wholesale_sale', 'customer_receivable', 'sale_return'] as $module) {
            array_push(
                $files,
                ...self::phpFilesIn(
                    self::projectRoot().'/resources/views/backend/modules/'.$module,
                ),
            );
        }
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
