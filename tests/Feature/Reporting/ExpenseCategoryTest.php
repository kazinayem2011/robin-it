<?php

namespace Tests\Feature\Reporting;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\ProfitAndLoss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spending categories were a constant in the Expense model, which meant no
 * shop could change the list without a deploy. They are rows now.
 */
class ExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** The ten that used to be hardcoded come with the migration. */
    public function test_the_shop_starts_with_the_categories_it_had_before(): void
    {
        $slugs = ExpenseCategory::pluck('slug')->all();

        foreach ([
            'rent', 'salaries', 'utilities', 'delivery', 'packaging',
            'marketing', 'equipment', 'fees', 'maintenance', 'other',
        ] as $expected) {
            $this->assertContains($expected, $slugs);
        }

        $this->assertTrue(ExpenseCategory::where('slug', 'rent')->value('is_active'));
    }

    public function test_an_admin_can_add_a_category(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/expense-categories', ['name' => 'Legal & accounting'])
            ->assertStatus(201);

        $category = ExpenseCategory::where('name', 'Legal & accounting')->first();

        $this->assertNotNull($category);
        $this->assertSame('legal-accounting', $category->slug);
        $this->assertTrue($category->is_active);
    }

    public function test_it_can_be_renamed(): void
    {
        $admin = $this->admin();
        $category = ExpenseCategory::where('slug', 'fees')->first();

        $this->actingAs($admin)
            ->patchJson("/api/admin/expense-categories/{$category->id}", ['name' => 'Banking'])
            ->assertStatus(200);

        $category->refresh();

        $this->assertSame('Banking', $category->name);
        // The slug is what filed expenses are joined by; a rename is not a
        // different category.
        $this->assertSame('fees', $category->slug);
    }

    public function test_two_categories_cannot_share_a_name(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/expense-categories', ['name' => 'Utilities'])
            ->assertStatus(422);
    }

    public function test_an_unused_category_is_deleted_outright(): void
    {
        $admin = $this->admin();
        $id = $this->actingAs($admin)
            ->postJson('/api/admin/expense-categories', ['name' => 'Temporary'])
            ->json('data.id');

        $this->actingAs($admin)->deleteJson("/api/admin/expense-categories/{$id}")->assertStatus(200);

        $this->assertNull(ExpenseCategory::find($id));
    }

    /**
     * The money was still spent. Losing the record of it to tidy up a list
     * would be the wrong trade.
     */
    public function test_a_category_in_use_is_hidden_rather_than_deleted(): void
    {
        $admin = $this->admin();
        $category = ExpenseCategory::where('slug', 'rent')->first();

        Expense::create([
            'expense_category_id' => $category->id,
            'amount' => 25000,
            'description' => 'Shop rent',
            'incurred_on' => now()->toDateString(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/expense-categories/{$category->id}")
            ->assertStatus(200);

        $category->refresh();

        $this->assertFalse($category->is_active, 'A category with spending was deleted.');
        $this->assertSame(1, Expense::count());
    }

    /** A hidden category stops being offered on the expense form. */
    public function test_a_hidden_category_is_not_offered_for_new_expenses(): void
    {
        $admin = $this->admin();
        ExpenseCategory::where('slug', 'rent')->update(['is_active' => false]);

        $props = $this->actingAs($admin)->get('/admin/expenses')
            ->assertStatus(200)->viewData('page')['props'];

        $offered = collect($props['categories'])->pluck('label');

        $this->assertNotContains('Rent & premises', $offered);
        $this->assertContains('Utilities', $offered);
    }

    /**
     * Deleting a category must not take the spending with it: the expense
     * survives, uncategorised, and still counts towards the total.
     */
    public function test_spending_survives_its_category_being_removed(): void
    {
        $category = ExpenseCategory::create([
            'name' => 'Short lived', 'slug' => 'short-lived', 'sort_order' => 99,
        ]);

        $expense = Expense::create([
            'expense_category_id' => $category->id,
            'amount' => 4000,
            'description' => 'One-off cost',
            'incurred_on' => now()->toDateString(),
        ]);

        $category->delete();
        $expense->refresh();

        $this->assertNull($expense->expense_category_id);
        $this->assertSame(4000.0, $expense->amount);
        $this->assertSame('Uncategorised', $expense->category_label);

        $statement = ProfitAndLoss::statement();
        $this->assertSame(4000.0, $statement['expenses']['total'], 'Orphaned spending left the total.');
    }

    public function test_a_customer_cannot_manage_categories(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)
            ->postJson('/api/admin/expense-categories', ['name' => 'Sneaky'])
            ->assertStatus(403);

        $this->actingAs($customer)->get('/admin/expense-categories')->assertRedirect();
    }

    /**
     * Stock is not an expense, and a category named for it would count the
     * same money twice. The shop is warned rather than blocked — it may have a
     * reason, and being told why is more use than being refused.
     */
    public function test_inventory_sounding_names_are_recognised(): void
    {
        foreach (['Stock purchases', 'Inventory', 'Goods bought', 'Product cost'] as $name) {
            $this->assertTrue(ExpenseCategory::looksLikeInventory($name), $name);
        }

        foreach (['Rent & premises', 'Salaries & wages', 'Marketing'] as $name) {
            $this->assertFalse(ExpenseCategory::looksLikeInventory($name), $name);
        }

        // Warned, not refused.
        $this->actingAs($this->admin())
            ->postJson('/api/admin/expense-categories', ['name' => 'Stock purchases'])
            ->assertStatus(201);
    }
}
