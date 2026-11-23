<?php

namespace Tests\Feature;

use App\Enums\Reporting\ReportingPeriodStatus;
use App\Exceptions\Reporting\InvalidReportingPeriodException;
use App\Http\Controllers\backend\ReportingPeriodController;
use App\Models\backend\User;
use App\Services\Reporting\ReportingPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReportingPeriodServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_builds_calendar_dates_and_records_open_event(): void
    {
        $user = $this->user();

        $period = app(ReportingPeriodService::class)->create(2026, 2, $user->id);

        $this->assertSame(ReportingPeriodStatus::Open, $period->status);
        $this->assertSame('2026-02-01', $period->starts_on->toDateString());
        $this->assertSame('2026-02-28', $period->ends_on->toDateString());
        $this->assertSame(['OPENED'], $period->events->pluck('action')->all());
        $this->assertSame($user->id, $period->events->first()->actor_id);
    }

    public function test_duplicate_period_cannot_be_created(): void
    {
        $user = $this->user();
        $service = app(ReportingPeriodService::class);
        $service->create(2026, 11, $user->id);

        $this->expectException(InvalidReportingPeriodException::class);
        $this->expectExceptionMessage('This reporting period already exists.');

        $service->create(2026, 11, $user->id);
    }

    public function test_close_and_reopen_require_valid_transitions_and_keep_audit_history(): void
    {
        $this->travelTo('2026-12-01 10:00:00');
        $user = $this->user();
        $service = app(ReportingPeriodService::class);
        $period = $service->create(2026, 11, $user->id);

        $closed = $service->close($period, 'Month-end review completed.', $user->id);
        $this->assertSame(ReportingPeriodStatus::Closed, $closed->status);
        $this->assertSame($user->id, $closed->closed_by);
        $this->assertNotNull($closed->closed_at);

        $reopened = $service->reopen($closed, 'Correction was approved.', $user->id);
        $this->assertSame(ReportingPeriodStatus::Open, $reopened->status);
        $this->assertNull($reopened->closed_by);
        $this->assertNull($reopened->closed_at);
        $this->assertSame(['OPENED', 'CLOSED', 'REOPENED'], $reopened->events->pluck('action')->all());
        $this->assertSame('Correction was approved.', $reopened->events->last()->reason);

        $this->expectException(InvalidReportingPeriodException::class);
        $this->expectExceptionMessage('Only a Closed reporting period can be reopened.');
        $service->reopen($reopened, 'Duplicate reopen attempt.', $user->id);
    }

    public function test_period_cannot_be_closed_before_its_end_date(): void
    {
        $this->travelTo('2026-11-19 10:00:00');
        $user = $this->user();
        $period = app(ReportingPeriodService::class)->create(2026, 11, $user->id);

        $this->expectException(InvalidReportingPeriodException::class);
        $this->expectExceptionMessage('A reporting period can be closed only after its end date.');

        app(ReportingPeriodService::class)->close($period, 'Premature close attempt.', $user->id);
    }

    public function test_reporting_period_list_uses_searchable_datatable_response(): void
    {
        $user = $this->user();
        $service = app(ReportingPeriodService::class);
        $service->create(2026, 10, $user->id);
        $service->create(2026, 11, $user->id);
        $request = Request::create('/reporting/periods/list', 'POST', [
            'draw' => 4,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => '2026-11'],
            'order' => [['column' => 1, 'dir' => 'desc']],
        ]);
        $request->setUserResolver(fn (): User => $user);

        $response = app(ReportingPeriodController::class)
            ->listAjax($request)
            ->getData(true);

        $this->assertSame(4, $response['draw']);
        $this->assertSame(2, $response['recordsTotal']);
        $this->assertSame(1, $response['recordsFiltered']);
        $this->assertCount(1, $response['data']);
        $this->assertCount(7, $response['data'][0]);
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Reporting Period User',
            'email' => 'reporting-period@example.test',
            'password' => Hash::make('password'),
        ]);
    }
}
