<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * There was no upload endpoint at all. The admin cropper handed a base64 data
 * URL straight into image_path — a VARCHAR(255) column — so an image could
 * never actually be stored.
 */
class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_an_admin_can_upload_an_image(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/admin/media', [
            'image' => UploadedFile::fake()->image('banner.jpg', 1200, 675),
            'folder' => 'banners',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('error', false)
            ->assertJsonStructure(['data' => ['path', 'disk_path', 'name', 'size']]);

        $path = $response->json('data.disk_path');
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('uploads/banners/', $path);
    }

    public function test_the_returned_path_is_publicly_addressable(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/admin/media', [
            'image' => UploadedFile::fake()->image('cover.png'),
            'folder' => 'blogs',
        ]);

        // This is what gets stored in image_path and rendered in an <img src>.
        $this->assertStringStartsWith('/storage/uploads/blogs/', $response->json('data.path'));
    }

    public function test_the_client_filename_is_never_reused(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/admin/media', [
            'image' => UploadedFile::fake()->image('../../evil shell.php.jpg'),
        ]);

        $name = $response->json('data.name');

        $this->assertStringNotContainsString('evil', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringNotContainsString('.php', $name);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.jpg$/', $name);
    }

    public static function rejectedFileProvider(): array
    {
        return [
            'php script' => ['payload.php', 'text/x-php'],
            'html' => ['page.html', 'text/html'],
            'svg with script' => ['icon.svg', 'image/svg+xml'],
            'plain text' => ['notes.txt', 'text/plain'],
        ];
    }

    #[DataProvider('rejectedFileProvider')]
    public function test_non_image_uploads_are_refused(string $filename, string $mime): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/admin/media', [
            'image' => UploadedFile::fake()->create($filename, 16, $mime),
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_oversized_images_are_refused(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/admin/media', [
            'image' => UploadedFile::fake()->create('huge.jpg', 6144, 'image/jpeg'),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('under 5MB', $response->json('message'));
    }

    public function test_an_unknown_destination_folder_is_refused(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/media', [
            'image' => UploadedFile::fake()->image('a.jpg'),
            'folder' => '../../../public',
        ])->assertStatus(422);

        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_a_customer_cannot_upload(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson('/api/admin/media', ['image' => UploadedFile::fake()->image('a.jpg')]);

        $this->assertContains($response->status(), [302, 403]);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_a_guest_cannot_upload(): void
    {
        $response = $this->postJson('/api/admin/media', [
            'image' => UploadedFile::fake()->image('a.jpg'),
        ]);

        $this->assertContains($response->status(), [401, 302, 403]);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_an_admin_can_delete_an_upload(): void
    {
        $admin = $this->admin();

        $path = $this->actingAs($admin)->postJson('/api/admin/media', [
            'image' => UploadedFile::fake()->image('a.jpg'),
        ])->json('data.path');

        $this->actingAs($admin)->deleteJson('/api/admin/media', ['path' => $path])
            ->assertStatus(200);

        Storage::disk('public')->assertMissing(str_replace('/storage/', '', $path));
    }

    public static function protectedPathProvider(): array
    {
        return [
            'outside uploads' => ['/storage/important.jpg'],
            'traversal' => ['/storage/uploads/../../.env'],
            'app config' => ['../config/app.php'],
        ];
    }

    /**
     * Deletion is scoped to the uploads directory so it cannot be turned into a
     * way to remove seeded artwork or anything else on the disk.
     */
    #[DataProvider('protectedPathProvider')]
    public function test_deletion_cannot_escape_the_uploads_directory(string $path): void
    {
        Storage::disk('public')->put('important.jpg', 'seeded artwork');

        $this->actingAs($this->admin())
            ->deleteJson('/api/admin/media', ['path' => $path])
            ->assertStatus(422);

        Storage::disk('public')->assertExists('important.jpg');
    }
}
