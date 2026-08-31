<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Who may upload an image, and where.
 *
 * The endpoint was gated on `marketing` alone. A storekeeper's role covers
 * stock, catalogue and orders — so they could edit a product and could not add
 * a photograph to it. Product photography is catalogue work; the endpoint just
 * happens to be shared with banners and blog covers, which are marketing.
 */
class MediaUploadAccessTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => $role]);

        return $user->refresh();
    }

    private function upload(User $user, string $folder)
    {
        Storage::fake('public');

        return $this->actingAs($user)->postJson('/api/admin/media', [
            'image' => UploadedFile::fake()->image('shot.jpg', 800, 800),
            'folder' => $folder,
        ]);
    }

    /** The gap this closes. */
    public function test_a_storekeeper_can_upload_a_product_photo(): void
    {
        $this->upload($this->staff('storekeeper'), 'products')->assertCreated();
    }

    public function test_a_storekeeper_cannot_replace_a_homepage_banner(): void
    {
        $this->upload($this->staff('storekeeper'), 'banners')->assertForbidden();
    }

    public function test_an_owner_can_upload_to_either(): void
    {
        $owner = $this->staff('admin');

        $this->upload($owner, 'products')->assertCreated();
        $this->upload($owner, 'banners')->assertCreated();
    }

    public function test_a_manager_can_upload_to_either(): void
    {
        $manager = $this->staff('manager');

        $this->upload($manager, 'products')->assertCreated();
        $this->upload($manager, 'banners')->assertCreated();
    }

    /** Neither ability, so neither folder. */
    public function test_an_accountant_cannot_upload_at_all(): void
    {
        $accountant = $this->staff('accountant');

        $this->upload($accountant, 'products')->assertForbidden();
        $this->upload($accountant, 'banners')->assertForbidden();
    }

    public function test_a_customer_cannot_upload(): void
    {
        $this->upload($this->staff('customer'), 'products')->assertForbidden();
    }

    /** `folder` still cannot be used to write outside the known folders. */
    public function test_an_unknown_folder_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->staff('admin'))
            ->postJson('/api/admin/media', [
                'image' => UploadedFile::fake()->image('shot.jpg'),
                'folder' => '../../secrets',
            ])
            ->assertStatus(422);
    }

    public function test_the_uploaded_file_lands_where_it_says(): void
    {
        Storage::fake('public');

        $path = $this->actingAs($this->staff('storekeeper'))
            ->postJson('/api/admin/media', [
                'image' => UploadedFile::fake()->image('shot.jpg', 800, 800),
                'folder' => 'products',
            ])
            ->assertCreated()
            ->json('data.disk_path');

        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('uploads/products/', $path);
    }
}
