<?php

namespace Tests\Unit;

use App\Support\PermAbility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PermAbilityTest extends TestCase
{
    #[DataProvider('routeAbilityProvider')]
    public function test_route_names_resolve_to_the_expected_ability(string $route, string $method, string $ability): void
    {
        $this->assertSame($ability, PermAbility::inferByRoute($route, $method));
    }

    public static function routeAbilityProvider(): array
    {
        return [
            ['inventory.part-categories.index', 'GET', 'view'],
            ['inventory.part-categories.list.ajax', 'POST', 'view'],
            ['inventory.part-categories.select2', 'GET', 'view'],
            ['inventory.part-categories.createModal', 'GET', 'add'],
            ['inventory.part-categories.store', 'POST', 'add'],
            ['inventory.part-categories.editModal', 'GET', 'edit'],
            ['inventory.part-categories.update', 'PUT', 'edit'],
            ['inventory.part-categories.destroy', 'DELETE', 'delete'],
        ];
    }
}
