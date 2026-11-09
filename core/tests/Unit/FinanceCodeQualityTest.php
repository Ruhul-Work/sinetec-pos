<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class FinanceCodeQualityTest extends TestCase
{
    private const MAX_LINE_LENGTH = 160;

    #[DataProvider('financeSourceFileProvider')]
    public function test_finance_source_lines_remain_readable(string $path): void
    {
        foreach ($this->readLines($path) as $lineNumber => $line) {
            $this->assertLessThanOrEqual(
                self::MAX_LINE_LENGTH,
                mb_strlen($line),
                $this->failureMessage($path, $lineNumber, 'Line exceeds the Finance readability limit.'),
            );
        }
    }

    #[DataProvider('financeBladeFileProvider')]
    public function test_finance_blades_do_not_use_minified_structural_markup(string $path): void
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
    public static function financeSourceFileProvider(): iterable
    {
        foreach (self::financePhpFiles() as $path) {
            yield self::relativePath($path) => [$path];
        }

        foreach (self::financeBladeFiles() as $path) {
            yield self::relativePath($path) => [$path];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function financeBladeFileProvider(): iterable
    {
        foreach (self::financeBladeFiles() as $path) {
            yield self::relativePath($path) => [$path];
        }
    }

    /** @return list<string> */
    private static function financePhpFiles(): array
    {
        $root = self::projectRoot();
        $files = [];

        foreach ([
            $root.'/app/Services/Finance',
            $root.'/app/Models/Finance',
        ] as $directory) {
            array_push($files, ...self::phpFilesIn($directory));
        }

        foreach (self::financeControllerNames() as $controllerName) {
            $files[] = $root.'/app/Http/Controllers/backend/'.$controllerName.'.php';
        }

        foreach (self::financeModelNames() as $modelName) {
            $files[] = $root.'/app/Models/backend/'.$modelName.'.php';
        }

        $files[] = $root.'/routes/web.php';

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private static function financeBladeFiles(): array
    {
        $viewRoot = self::projectRoot().'/resources/views/backend/modules';
        $files = [];

        foreach (self::financeViewDirectories() as $directoryName) {
            array_push($files, ...self::phpFilesIn($viewRoot.'/'.$directoryName));
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

    /** @return list<string> */
    private static function financeControllerNames(): array
    {
        return [
            'AccountController',
            'AccountLedgerController',
            'AccountTypeController',
            'BranchAccountController',
            'CapitalInvestmentController',
            'CapitalWithdrawalController',
            'CashBankBookController',
            'CashBankTransferController',
            'FinanceAccountMappingController',
            'FinanceOpeningBalanceController',
            'FiscalYearClosingController',
            'FiscalYearController',
            'JournalController',
            'LoanReceiptController',
            'LoanRepaymentController',
            'PurchasePaymentController',
            'SupplierDueSummaryController',
            'TrialBalanceController',
        ];
    }

    /** @return list<string> */
    private static function financeModelNames(): array
    {
        return [
            'Account',
            'AccountOpeningBalance',
            'AccountType',
            'BranchAccount',
            'FiscalYear',
            'JournalEntry',
            'JournalEntryLine',
        ];
    }

    /** @return list<string> */
    private static function financeViewDirectories(): array
    {
        return [
            'account',
            'accountBranch',
            'accountOpenBalance',
            'accountType',
            'account_ledger',
            'capital_investment',
            'capital_withdrawal',
            'cash_bank_book',
            'cash_bank_transfer',
            'finance_account_mapping',
            'finance_opening_balance',
            'fiscal_year_closing',
            'fiscal_years',
            'journals',
            'loan_receipt',
            'loan_repayment',
            'purchase_payment',
            'supplier_due_summary',
            'trial_balance',
        ];
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
