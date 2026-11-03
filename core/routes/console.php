<?php

use App\Services\Finance\PurchaseJournalService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('finance:reconcile-purchase-journals {--branch= : Limit reconciliation to one branch ID} {--dry-run : Count missing drafts without creating them}', function () {
    $branchId = $this->option('branch') !== null ? (int) $this->option('branch') : null;
    if ($branchId !== null && $branchId < 1) {
        $this->error('The branch option must be a positive branch ID.');

        return 1;
    }

    if ($this->option('dry-run')) {
        $missingReceipts = DB::table('purchase_receipt as pr')
            ->where('pr.status', 'POSTED')
            ->when($branchId, fn ($query) => $query->where('pr.branch_id', $branchId))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('journal_entries as je')
                ->whereColumn('je.source_id', 'pr.id')->where('je.source_type', 'purchase_receipt'))
            ->count();
        $missingReturns = DB::table('purchase_return as pr')
            ->where('pr.status', 'POSTED')
            ->when($branchId, fn ($query) => $query->where('pr.branch_id', $branchId))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('journal_entries as je')
                ->whereColumn('je.source_id', 'pr.id')->where('je.source_type', 'purchase_return'))
            ->count();

        $this->table(['Document', 'Missing draft journals'], [
            ['Purchase Receipt', $missingReceipts],
            ['Purchase Return', $missingReturns],
        ]);

        return 0;
    }

    $summary = app(PurchaseJournalService::class)
        ->reconcilePostedDocuments($branchId);
    $this->table(['Result', 'Count'], collect($summary)->map(fn ($count, $label) => [
        str($label)->replace('_', ' ')->title()->toString(),
        $count,
    ])->values()->all());

    return 0;
})->purpose('Create missing idempotent draft journals for posted purchase receipts and returns');
