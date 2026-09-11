<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One file, any number of products pointing at it.
 *
 * Copying a product shares its photographs rather than duplicating them on
 * disk: the copy is usually the same machine with a different amount of memory
 * in it, so the same picture is the right picture, and a second identical file
 * is waste that also has to be kept in step.
 *
 * That only holds while nothing deletes the file out from under the others.
 * Removing an image from a product's gallery already only unlinks the row, and
 * deleting a product removes no files at all — but the media endpoint deletes
 * outright, and did so without asking whether anybody still wanted it.
 */
class SharedImageTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = 'uploads/products/shared.jpg';

    private const PUBLIC_PATH = '/storage/uploads/products/shared.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function product(string $name): Product
    {
        $shelf = Category::firstOrCreate(
            ['slug' => 'laptop'],
            ['name' => 'Laptop', 'is_active' => true],
        );

        return Product::create([
            'category_id' => $shelf->id,
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'price' => 1000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
    }

    private function remove(): TestResponse
    {
        return $this->actingAs($this->admin())
            ->deleteJson('/api/admin/media', ['path' => self::PUBLIC_PATH]);
    }

    public function test_a_file_two_products_share_cannot_be_deleted(): void
    {
        foreach (['Original', 'Copy'] as $name) {
            $this->product($name)->images()->create([
                'image_path' => self::PUBLIC_PATH,
                'is_primary' => true,
            ]);
        }

        $this->remove()->assertStatus(422);
    }

    /** Named, so whoever is refused knows what to look for. */
    public function test_the_refusal_says_how_many_still_want_it(): void
    {
        $this->product('Original')->images()->create([
            'image_path' => self::PUBLIC_PATH, 'is_primary' => true,
        ]);

        $this->remove()
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '1 product'));
    }

    public function test_an_option_counts_as_a_user_of_it(): void
    {
        $product = $this->product('Original');
        $product->variants()->create([
            'name' => '16GB', 'price' => 1000, 'image_url' => self::PUBLIC_PATH,
        ]);

        $this->remove()->assertStatus(422);
    }

    public function test_a_brand_logo_counts_too(): void
    {
        Brand::create(['name' => 'ASUS', 'slug' => 'asus', 'logo_path' => self::PUBLIC_PATH]);

        $this->remove()->assertStatus(422);
    }

    /** Nobody should be able to delete a customer's photograph by its address. */
    public function test_an_avatar_counts_too(): void
    {
        User::factory()->create(['avatar' => self::PUBLIC_PATH]);

        $this->remove()->assertStatus(422);
    }

    /** A file nothing points at is an upload somebody abandoned. */
    public function test_a_file_nobody_uses_can_be_deleted(): void
    {
        Storage::disk('public')->put(self::PATH, 'x');

        $this->remove()->assertOk();

        Storage::disk('public')->assertMissing(self::PATH);
    }

    /**
     * Unlinking is not deleting. Taking a photo off one product leaves the
     * file alone, which is what makes it safe for the other to keep using it.
     */
    public function test_removing_it_from_a_gallery_leaves_the_file_alone(): void
    {
        Storage::disk('public')->put(self::PATH, 'x');

        $original = $this->product('Original');
        $copy = $this->product('Copy');

        foreach ([$original, $copy] as $p) {
            $p->images()->create(['image_path' => self::PUBLIC_PATH, 'is_primary' => true]);
        }

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$copy->id}", ['images' => []])
            ->assertOk();

        $this->assertSame(0, $copy->fresh()->images()->count());
        $this->assertSame(1, $original->fresh()->images()->count());
        Storage::disk('public')->assertExists(self::PATH);
    }
}
