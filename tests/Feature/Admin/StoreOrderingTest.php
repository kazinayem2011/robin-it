<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sort_order defaults to 0 in the schema and the admin form never collected it,
 * so every newly added branch sorted above the flagship showroom on the
 * storefront.
 */
class StoreOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Branch',
            'branch_type' => 'Regional Showroom',
            'city' => 'Sylhet',
            'address' => 'Zindabazar, Sylhet',
            'phone' => '+880 1700-112277',
            'opening_hours' => '10:00 AM - 08:00 PM',
            'is_active' => true,
        ], $overrides);
    }

    private function seedBranches(): void
    {
        foreach ([1 => 'Flagship', 2 => 'Second', 3 => 'Third'] as $order => $name) {
            Store::create($this->payload([
                'name' => $name,
                'sort_order' => $order,
            ]));
        }
    }

    public function test_a_new_branch_is_appended_rather_than_promoted(): void
    {
        $this->seedBranches();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/stores', $this->payload(['name' => 'Newest']))
            ->assertStatus(201);

        $names = Store::active()->pluck('name')->all();

        $this->assertSame(['Flagship', 'Second', 'Third', 'Newest'], $names);
    }

    public function test_an_explicit_position_is_respected(): void
    {
        $this->seedBranches();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/stores', $this->payload(['name' => 'Promoted', 'sort_order' => 0]))
            ->assertStatus(201);

        $this->assertSame('Promoted', Store::active()->first()->name);
    }

    public function test_the_first_branch_starts_at_one(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/stores', $this->payload(['name' => 'Only Branch']))
            ->assertStatus(201);

        $this->assertSame(1, Store::first()->sort_order);
    }

    public function test_updating_a_branch_without_a_position_keeps_it(): void
    {
        $this->seedBranches();
        $store = Store::where('name', 'Second')->first();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/stores/{$store->id}", $this->payload(['name' => 'Second Renamed']))
            ->assertStatus(200);

        $store->refresh();
        $this->assertSame('Second Renamed', $store->name);
        $this->assertSame(2, $store->sort_order, 'position should be untouched');
    }

    /** Equal positions must not shuffle between requests. */
    public function test_branches_sharing_a_position_keep_a_stable_order(): void
    {
        $a = Store::create($this->payload(['name' => 'A', 'sort_order' => 5]));
        $b = Store::create($this->payload(['name' => 'B', 'sort_order' => 5]));

        $this->assertSame(
            [$a->id, $b->id],
            Store::active()->pluck('id')->all()
        );
    }

    public function test_inactive_branches_stay_off_the_storefront(): void
    {
        $this->seedBranches();
        Store::create($this->payload(['name' => 'Closed Branch', 'is_active' => false]));

        $this->getJson('/api/stores')
            ->assertStatus(200)
            ->assertJsonMissing(['name' => 'Closed Branch']);
    }
}
