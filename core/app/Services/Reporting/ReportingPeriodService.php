<?php
namespace App\Services\Reporting;

use App\Enums\Reporting\ReportingPeriodStatus;
use App\Exceptions\Reporting\InvalidReportingPeriodException;
use App\Models\Reporting\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReportingPeriodService
{
    public function create(int $year, int $month, int $userId): ReportingPeriod
    {
        if ($year < 2000 || $year > 9999 || $month < 1 || $month > 12) {
            throw new InvalidReportingPeriodException('Choose a valid reporting year and month.');
        }

        return DB::transaction(function () use ($year, $month, $userId): ReportingPeriod {
            if (ReportingPeriod::query()->where('year', $year)->where('month', $month)->lockForUpdate()->exists()) {
                throw new InvalidReportingPeriodException('This reporting period already exists.');
            }

            $start  = CarbonImmutable::create($year, $month, 1)->startOfMonth();
            $period = ReportingPeriod::query()->create([
                'year'      => $year,
                'month'     => $month,
                'starts_on' => $start->toDateString(),
                'ends_on'   => $start->endOfMonth()->toDateString(),
                'status'    => ReportingPeriodStatus::Open,
            ]);
            $period->events()->create([
                'action'   => 'OPENED',
                'actor_id' => $userId,
                'reason'   => 'Reporting period created as Open.',
            ]);

            return $period->fresh(['closedBy', 'events']);
        }, 3);
    }

    public function close(ReportingPeriod $period, string $reason, int $userId): ReportingPeriod
    {
        return DB::transaction(function () use ($period, $reason, $userId): ReportingPeriod {
            $period = ReportingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($period->status !== ReportingPeriodStatus::Open) {
                throw new InvalidReportingPeriodException('Only an Open reporting period can be closed.');
            }
            if (now()->toDateString() <= $period->ends_on->toDateString()) {
                throw new InvalidReportingPeriodException('A reporting period can be closed only after its end date.');
            }

            $reason   = $this->reason($reason);
            $closedAt = now();
            $period->update([
                'status'    => ReportingPeriodStatus::Closed,
                'closed_by' => $userId,
                'closed_at' => $closedAt,
            ]);
            $period->events()->create([
                'action'   => 'CLOSED',
                'actor_id' => $userId,
                'reason'   => $reason,
            ]);

            return $period->fresh(['closedBy', 'events']);
        }, 3);
    }

    public function reopen(ReportingPeriod $period, string $reason, int $userId): ReportingPeriod
    {
        return DB::transaction(function () use ($period, $reason, $userId): ReportingPeriod {
            $period = ReportingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($period->status !== ReportingPeriodStatus::Closed) {
                throw new InvalidReportingPeriodException('Only a Closed reporting period can be reopened.');
            }

            $reason = $this->reason($reason);
            $period->update([
                'status'    => ReportingPeriodStatus::Open,
                'closed_by' => null,
                'closed_at' => null,
            ]);
            $period->events()->create([
                'action'   => 'REOPENED',
                'actor_id' => $userId,
                'reason'   => $reason,
            ]);

            return $period->fresh(['closedBy', 'events']);
        }, 3);
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidReportingPeriodException('A reason of up to 1000 characters is required.');
        }

        return $reason;
    }
}
