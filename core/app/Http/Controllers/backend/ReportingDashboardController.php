<?php
namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportingDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $reports = [
            [
                'title'       => 'Stock Ledger',
                'description' => 'Review stock movement and running balances by inventory item.',
                'route'       => 'inventory.stock-ledger.index',
                'permission'  => 'inventory.stock-ledger.index',
                'icon'        => 'mdi:book-open-page-variant-outline',
                'tone'        => 'primary',
                'wash'        => '#eef5ff',
            ],
            [
                'title'       => 'Supplier Due Summary',
                'description' => 'Review outstanding supplier balances and purchase due amounts.',
                'route'       => 'purchase.supplier-dues.index',
                'permission'  => 'purchase.supplier-dues.index',
                'icon'        => 'mdi:account-cash-outline',
                'tone'        => 'warning',
                'wash'        => '#fff7e6',
            ],
            [
                'title'       => 'Purchase Price History',
                'description' => 'Compare actual purchase rates recorded on posted receipts.',
                'route'       => 'purchase.price-history.index',
                'permission'  => 'purchase.price-history.index',
                'icon'        => 'mdi:chart-line',
                'tone'        => 'info',
                'wash'        => '#eafaff',
            ],
            [
                'title'       => 'Customer Receivables',
                'description' => 'Review customer balances, collections and remaining dues.',
                'route'       => 'sales.customer-receivables.index',
                'permission'  => 'sales.customer-receivables.index',
                'icon'        => 'mdi:account-cash',
                'tone'        => 'success',
                'wash'        => '#ebfaef',
            ],
            [
                'title'       => 'Sales / Return Reconciliation',
                'description' => 'Check posted Sales and Returns against stock, payments and journals.',
                'route'       => 'sales.reconciliation.index',
                'permission'  => 'sales.reconciliation.index',
                'icon'        => 'mdi:clipboard-check-outline',
                'tone'        => 'danger',
                'wash'        => '#fff0f1',
            ],
            [
                'title'       => 'Cash / Bank Book',
                'description' => 'Review posted cash and bank movements by account and date.',
                'route'       => 'finance.cash-bank-book.index',
                'permission'  => 'finance.cash-bank-book.index',
                'icon'        => 'mdi:bank-transfer',
                'tone'        => 'primary',
                'wash'        => '#edf8ff',
            ],
            [
                'title'       => 'Account Ledger',
                'description' => 'Review posted account activity with a running balance.',
                'route'       => 'finance.account-ledger.index',
                'permission'  => 'finance.account-ledger.index',
                'icon'        => 'mdi:book-open-page-variant-outline',
                'tone'        => 'info',
                'wash'        => '#f0f7ff',
            ],
            [
                'title'       => 'Trial Balance',
                'description' => 'Verify posted debit and credit balances by account.',
                'route'       => 'finance.trial-balance.index',
                'permission'  => 'finance.trial-balance.index',
                'icon'        => 'mdi:scale-balance',
                'tone'        => 'success',
                'wash'        => '#f0f9f0',
            ],
            [
                'title'       => 'Monthly Stock Valuation',
                'description' => 'Value current stock balances using the selected period rate sheet.',
                'route'       => 'reporting.stock-valuation.index',
                'permission'  => 'reporting.stock-valuation.index',
                'icon'        => 'mdi:cash-multiple',
                'tone'        => 'primary',
                'wash'        => '#edf8ff',
            ],
            [
                'title'       => 'Monthly Purchase Report',
                'description' => 'Review posted purchase receipts, item lines, returns and supplier payments.',
                'route'       => 'reporting.monthly-purchase.index',
                'permission'  => 'reporting.monthly-purchase.index',
                'icon'        => 'mdi:file-document-outline',
                'tone'        => 'warning',
                'wash'        => '#fff7e6',
            ],
            [
                'title'       => 'Monthly Stock Report',
                'description' => 'Review opening, period IN/OUT and closing stock by warehouse.',
                'route'       => 'reporting.monthly-stock.index',
                'permission'  => 'reporting.monthly-stock.index',
                'icon'        => 'mdi:warehouse',
                'tone'        => 'success',
                'wash'        => '#ebfaef',
            ],
            [
                'title'       => 'Monthly Part Production',
                'description' => 'Review completed part batches, QC quantities and report-only costs.',
                'route'       => 'reporting.monthly-part-production.index',
                'permission'  => 'reporting.monthly-part-production.index',
                'icon'        => 'mdi:factory',
                'tone'        => 'primary',
                'wash'        => '#edf8ff',
            ],
        ];

        $user    = $request->user();
        $reports = collect($reports)
            ->filter(fn(array $report): bool => $user?->canDo($report['permission'], 'view') ?? false)
            ->values();
        $canManagePeriods = $user?->canDo('reporting.periods.index', 'view') ?? false;
        $canManageRateSheets = $user?->canDo('reporting.rate-sheets.index', 'view') ?? false;

        return view('backend.modules.reporting.index', compact('reports', 'canManagePeriods', 'canManageRateSheets'));
    }
}
