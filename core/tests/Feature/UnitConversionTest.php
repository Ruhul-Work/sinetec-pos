<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureBranchScope;
use App\Models\backend\Unit;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            Authenticate::class,
            CheckPermission::class,
            EnsureBranchScope::class,
        ]);
    }

    public function test_a_conversion_can_be_created_and_blocks_a_reverse_duplicate(): void
    {
        $kilogram = $this->unit('Kilogram', 'KG', 3);
        $gram = $this->unit('Gram', 'G', 3);

        $this->postJson(route('units.conversions.store'), [
            'from_unit_id' => $kilogram->id,
            'to_unit_id' => $gram->id,
            'factor' => 1000,
        ])->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('unit_conversions', [
            'from_unit_id' => $kilogram->id,
            'to_unit_id' => $gram->id,
            'factor' => 1000,
        ]);

        $this->postJson(route('units.conversions.store'), [
            'from_unit_id' => $gram->id,
            'to_unit_id' => $kilogram->id,
            'factor' => 0.001,
        ])->assertUnprocessable()->assertJsonValidationErrors('from_unit_id');
    }

    public function test_a_unit_cannot_convert_to_itself(): void
    {
        $piece = $this->unit('Piece', 'PCS', 0);

        $this->postJson(route('units.conversions.store'), [
            'from_unit_id' => $piece->id,
            'to_unit_id' => $piece->id,
            'factor' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['from_unit_id', 'to_unit_id']);
    }

    public function test_a_unit_used_by_a_conversion_cannot_be_deleted(): void
    {
        $dozen = $this->unit('Dozen', 'DOZ', 0);
        $piece = $this->unit('Piece', 'PCS', 0);

        $this->postJson(route('units.conversions.store'), [
            'from_unit_id' => $dozen->id,
            'to_unit_id' => $piece->id,
            'factor' => 12,
        ])->assertCreated();

        $this->deleteJson(route('units.destroy', $dozen))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertNull($dozen->fresh()->deleted_at);
    }

    private function unit(string $name, string $code, int $precision): Unit
    {
        return Unit::create([
            'name' => $name,
            'code' => $code,
            'precision' => $precision,
            'is_active' => true,
        ]);
    }
}
