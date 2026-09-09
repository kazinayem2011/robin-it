<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A brand shelf knowing which brand it is.
 *
 * The shop lists brands the way the trade does: "ASUS" is a shelf under Brand
 * PC with its own page and its own URL. 144 of those exist, and nothing joined
 * them to `brands` — the two were matched on a lowercased, trimmed name at
 * render time, so 44 of the 144 carried no logo, a shelf named "ASUS" could
 * not reach the brand row "ASUS (Network)", and either being renamed quietly
 * broke the pair.
 */
class CategoryBrandTest extends TestCase
{
    use RefreshDatabase;

    private function brand(string $name, ?string $logo = 'brands/x.svg'): Brand
    {
        return Brand::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'logo_path' => $logo,
        ]);
    }

    private function shelf(string $name, ?int $parent = null, ?int $brand = null): Category
    {
        return Category::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'parent_id' => $parent,
            'brand_id' => $brand,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** One product on a shelf, so the menu treats it as worth showing. */
    private function stock(Category $shelf): void
    {
        $product = Product::create([
            'name' => $shelf->name.' item',
            'slug' => $shelf->slug.'-item',
            'category_id' => $shelf->id,
            'price' => 100,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $product->categories()->syncWithoutDetaching([$shelf->id]);
    }

    public function test_a_shelf_can_stand_for_a_brand(): void
    {
        $asus = $this->brand('ASUS');
        $shelf = $this->shelf('ASUS', null, $asus->id);

        $this->assertSame($asus->id, $shelf->brand->id);
    }

    /** Most shelves are product lines, not makers. */
    public function test_an_ordinary_shelf_stands_for_nothing(): void
    {
        $this->assertNull($this->shelf('Gaming Laptop')->brand);
    }

    /**
     * The join is the key, not the name.
     *
     * This is the case the old name matching could never answer: the shelf and
     * the brand are deliberately spelled differently, which is real — "ASUS
     * (Network)" is a brand row, and the shelf under Networking is "ASUS".
     */
    public function test_the_pair_holds_when_the_names_do_not_agree(): void
    {
        $brand = $this->brand('ASUS (Network)');
        $shelf = $this->shelf('ASUS', null, $brand->id);

        $this->assertSame('ASUS (Network)', $shelf->brand->name);
    }

    /** And through a rename of either side. */
    public function test_the_pair_survives_a_rename(): void
    {
        $brand = $this->brand('Asus');
        $shelf = $this->shelf('Asus', null, $brand->id);

        $brand->update(['name' => 'ASUSTeK']);
        $shelf->update(['name' => 'ASUS Gaming']);

        $this->assertSame($brand->id, $shelf->fresh()->brand->id);
    }

    /**
     * A deleted brand empties the shelf's claim without taking the shelf.
     *
     * The products filed there are not the brand's to remove — cascading would
     * delete the shelf, and `products.category_id` cascades in turn.
     */
    public function test_deleting_a_brand_leaves_its_shelf_standing(): void
    {
        $brand = $this->brand('Corsair');
        $shelf = $this->shelf('Corsair', null, $brand->id);

        $brand->delete();

        $shelf = $shelf->fresh();
        $this->assertNotNull($shelf);
        $this->assertNull($shelf->brand_id);
    }

    public function test_an_admin_can_say_which_brand_a_shelf_is(): void
    {
        $brand = $this->brand('MSI');
        $shelf = $this->shelf('MSI');

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$shelf->id}", [
                'name' => 'MSI',
                'brand_id' => $brand->id,
            ])
            ->assertOk();

        $this->assertSame($brand->id, $shelf->fresh()->brand_id);
    }

    public function test_an_admin_can_take_the_brand_back_off_a_shelf(): void
    {
        $brand = $this->brand('MSI');
        $shelf = $this->shelf('MSI', null, $brand->id);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$shelf->id}", [
                'name' => 'MSI',
                'brand_id' => null,
            ])
            ->assertOk();

        $this->assertNull($shelf->fresh()->brand_id);
    }

    /**
     * A shelf can mint the brand it stands for.
     *
     * The shop's commonest inconsistency, and not hypothetical: 386 shelves
     * are named after makers `brands` has never heard of — Acer, AOC, Walton,
     * Tecno. Each looks right in the menu and then carries no logo, shows no
     * maker on the product page, and cannot be filtered or featured, because
     * all of those read `brands`.
     */
    public function test_a_shelf_can_create_the_brand_it_stands_for(): void
    {
        $shelf = $this->shelf('Acer');

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$shelf->id}", [
                'name' => 'Acer',
                'create_brand' => true,
            ])
            ->assertOk();

        $brand = Brand::whereRaw('LOWER(name) = ?', ['acer'])->first();

        $this->assertNotNull($brand, 'no brand was created for the shelf');
        $this->assertSame($brand->id, $shelf->fresh()->brand_id);
    }

    /**
     * Reused, not duplicated. ASUS is twenty shelves — one per parent — and
     * each of them saying "I am ASUS" must arrive at the same brand.
     */
    public function test_a_second_shelf_for_the_same_maker_reuses_its_brand(): void
    {
        $first = $this->shelf('ASUS');
        $second = $this->shelf('asus');

        foreach ([$first, $second] as $shelf) {
            $this->actingAs($this->admin())
                ->patchJson("/api/admin/categories/{$shelf->id}", [
                    'name' => $shelf->name,
                    'create_brand' => true,
                ])
                ->assertOk();
        }

        $this->assertSame(1, Brand::whereRaw('LOWER(name) = ?', ['asus'])->count());
        $this->assertSame($first->fresh()->brand_id, $second->fresh()->brand_id);
    }

    /** And an existing brand is adopted rather than shadowed by a copy. */
    public function test_minting_adopts_a_brand_that_already_exists(): void
    {
        $existing = $this->brand('Walton');
        $shelf = $this->shelf('Walton');

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$shelf->id}", [
                'name' => 'Walton',
                'create_brand' => true,
            ])
            ->assertOk();

        $this->assertSame($existing->id, $shelf->fresh()->brand_id);
        $this->assertSame(1, Brand::where('name', 'Walton')->count());
    }

    /**
     * What the form actually sends for "Not a brand shelf".
     *
     * A <select> has no null, only "", so this is the shape that arrives on
     * every save of an ordinary shelf — by far the commonest request this
     * endpoint sees, and one no other test here covers.
     */
    public function test_an_empty_brand_from_the_form_is_no_brand(): void
    {
        $shelf = $this->shelf('Gaming Laptop');

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$shelf->id}", [
                'name' => 'Gaming Laptop',
                'brand_id' => '',
                'create_brand' => false,
            ])
            ->assertOk();

        $this->assertNull($shelf->fresh()->brand_id);
    }

    /** Saying nothing still means nothing: most shelves are product lines. */
    public function test_a_shelf_saved_without_asking_mints_nothing(): void
    {
        $shelf = $this->shelf('Gaming Laptop');

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$shelf->id}", [
                'name' => 'Gaming Laptop',
            ])
            ->assertOk();

        $this->assertNull($shelf->fresh()->brand_id);
        $this->assertSame(0, Brand::where('name', 'Gaming Laptop')->count());
    }

    public function test_a_shelf_cannot_stand_for_a_brand_that_is_not_there(): void
    {
        $shelf = $this->shelf('MSI');

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$shelf->id}", [
                'name' => 'MSI',
                'brand_id' => 99999,
            ])
            ->assertStatus(422);
    }

    /**
     * The key beats the name, when both could answer.
     *
     * This is the whole point of having a key. The shelf is called "Lenovo"
     * and there is a brand row called "Lenovo", so the old name matching would
     * confidently attach the wrong logo; the shelf says it stands for ASUS,
     * and that is what it stands for.
     */
    public function test_the_shelf_own_brand_wins_over_one_of_the_same_name(): void
    {
        $namesake = $this->brand('Lenovo', 'brands/lenovo.svg');
        $actual = $this->brand('ASUS', 'brands/asus.svg');

        $root = $this->shelf('Laptop');
        $sub = $this->shelf('All Laptop', $root->id);
        $shelf = $this->shelf('Lenovo', $sub->id, $actual->id);

        foreach ([$root, $sub, $shelf] as $each) {
            $this->stock($each);
        }

        $node = collect(app(CategoryService::class)->getMegaMenuTree())
            ->firstWhere('id', $root->id);

        $this->assertSame(
            'brands/asus.svg',
            collect($node['subcategories'][0]['children'])
                ->firstWhere('name', 'Lenovo')['logo'],
        );
        $this->assertNotNull($namesake->fresh());
    }

    /**
     * The menu draws the logo from the shelf's brand.
     *
     * A drawn icon cannot say "ASUS", so a brand shelf carries its mark and
     * everything else falls back to a lettermark.
     */
    public function test_the_menu_takes_a_shelf_logo_from_its_brand(): void
    {
        $brand = $this->brand('Lenovo', 'brands/lenovo.svg');
        $root = $this->shelf('Laptop');
        $sub = $this->shelf('All Laptop', $root->id);
        $lenovo = $this->shelf('Lenovo', $sub->id, $brand->id);
        $line = $this->shelf('Gaming Laptop', $sub->id);

        /*
         * The menu only carries shelves that hold something — an empty shelf
         * is a dead end for a shopper — so each of these needs a product for
         * the tree to reach it.
         */
        foreach ([$root, $sub, $lenovo, $line] as $shelf) {
            $this->stock($shelf);
        }

        $node = collect(app(CategoryService::class)->getMegaMenuTree())
            ->firstWhere('id', $root->id);

        $children = collect($node['subcategories'][0]['children']);

        $this->assertSame(
            'brands/lenovo.svg',
            $children->firstWhere('name', 'Lenovo')['logo'],
        );
        $this->assertNull($children->firstWhere('name', 'Gaming Laptop')['logo']);
    }
}
