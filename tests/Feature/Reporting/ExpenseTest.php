<?php

namespace Tests\Feature\Reporting;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'rent',
            'amount' => 25000,
            'description' => 'Shop rent, August',
            'incurred_on' => now()->toDateString(),
        ], $overrides);
    }

    public function test_an_admin_can_record_an_expense(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/expenses', $this->payload())
            ->assertStatus(201);

        $expense = Expense::first();

        $this->assertSame('rent', $expense->category);
        $this->assertSame(25000.0, $expense->amount);
        // Who typed it in, so the entry can be asked about later.
        $this->assertSame($admin->id, $expense->user_id);
    }

    public function test_it_can_be_edited_and_removed(): void
    {
        $admin = $this->admin();

        $id = $this->actingAs($admin)->postJson('/api/admin/expenses', $this->payload())
            ->json('data.id');

        $this->actingAs($admin)
            ->patchJson("/api/admin/expenses/{$id}", $this->payload(['amount' => 26000]))
            ->assertStatus(200);

        $this->assertSame(26000.0, Expense::find($id)->amount);

        $this->actingAs($admin)->deleteJson("/api/admin/expenses/{$id}")->assertStatus(200);
        $this->assertNull(Expense::find($id));
    }

    public function test_a_customer_cannot_touch_expenses(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)
            ->postJson('/api/admin/expenses', $this->payload())
            ->assertStatus(403);

        $this->actingAs($customer)->get('/admin/expenses')->assertRedirect();
        $this->assertSame(0, Expense::count());
    }

    public function test_an_unknown_category_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/expenses', $this->payload(['category' => 'bribes']))
            ->assertStatus(422);

        $this->assertSame(0, Expense::count());
    }

    /**
     * Stock is not an expense — it is inventory until it sells, and it reaches
     * the accounts as cost of goods sold. Offering the category here would
     * invite counting the same money twice.
     */
    public function test_stock_is_not_an_expense_category(): void
    {
        foreach (['stock', 'inventory', 'purchase', 'purchases', 'goods'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, Expense::CATEGORIES);

            $this->actingAs($this->admin())
                ->postJson('/api/admin/expenses', $this->payload(['category' => $forbidden]))
                ->assertStatus(422);
        }
    }

    /** A bill that has not happened yet does not belong in a period's accounts. */
    public function test_an_expense_cannot_be_dated_in_the_future(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/expenses', $this->payload([
                'incurred_on' => now()->addWeek()->toDateString(),
            ]))
            ->assertStatus(422);
    }

    /** A January bill typed in February is January's. */
    public function test_it_is_filed_by_the_date_it_was_incurred(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/expenses', $this->payload([
            'incurred_on' => now()->subMonth()->startOfMonth()->toDateString(),
        ]))->assertStatus(201);

        $lastMonth = now()->subMonth();

        $this->assertSame(1, Expense::between(
            $lastMonth->copy()->startOfMonth()->toDateString(),
            $lastMonth->copy()->endOfMonth()->toDateString()
        )->count());

        $this->assertSame(0, Expense::between(
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString()
        )->count());
    }

    public function test_the_screen_totals_what_it_is_showing(): void
    {
        $admin = $this->admin();

        foreach ([['rent', 25000], ['salaries', 40000], ['rent', 5000]] as [$category, $amount]) {
            $this->actingAs($admin)->postJson('/api/admin/expenses',
                $this->payload(['category' => $category, 'amount' => $amount]));
        }

        $all = $this->actingAs($admin)->get('/admin/expenses')
            ->assertStatus(200)->viewData('page')['props'];
        $this->assertSame(70000.0, $all['total']);

        $rent = $this->actingAs($admin)->get('/admin/expenses?category=rent')
            ->viewData('page')['props'];
        $this->assertSame(30000.0, $rent['total'], 'The total must match the filter.');
        $this->assertCount(2, $rent['expenses']['data']);
    }
}
