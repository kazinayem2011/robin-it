<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What an upload is allowed to be called once it is on disk.
 *
 * Both upload endpoints renamed the file to a UUID and then took the extension
 * from whatever the browser called it. Laravel blocks `.php` and `.phtml`, so
 * the double-extension trick was covered — but `.html`, `.htm`, `.svg`,
 * `.xhtml` and `.shtml` all went through.
 *
 * A real PNG with `<script>` appended still passes getimagesize(), so it
 * validates as an image. Stored as `<uuid>.html` under public/storage it is
 * served as text/html, and the script runs on the shop's own origin — session
 * theft if an admin opens it, and a phishing page on the real domain either
 * way. The avatar endpoint put that within reach of anyone who registered.
 */
class UploadedFileExtensionTest extends TestCase
{
    use RefreshDatabase;

    /** A genuine image of the given type, carrying script after the image data. */
    private function polyglot(string $type = 'png'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'img').'.'.$type;
        $image = imagecreatetruecolor(10, 10);
        $type === 'gif' ? imagegif($image, $path) : imagepng($image, $path);
        imagedestroy($image);

        // Trailing bytes: the header still parses, so this is a valid image.
        file_put_contents($path, '<script>alert(document.domain)</script>', FILE_APPEND);

        return $path;
    }

    public static function hostileNames(): array
    {
        return [
            'html' => ['payload.html'],
            'htm' => ['payload.htm'],
            'svg' => ['payload.svg'],
            'xhtml' => ['payload.xhtml'],
            'shtml' => ['payload.shtml'],
            'php' => ['payload.php'],
            'phtml' => ['payload.phtml'],
            'double extension' => ['payload.html.png'],
            'no extension at all' => ['payload'],
        ];
    }

    #[DataProvider('hostileNames')]
    public function test_the_admin_upload_stores_images_under_an_image_extension(string $filename): void
    {
        Storage::fake('public');

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/media', [
                'image' => new UploadedFile($this->polyglot(), $filename, 'image/png', null, true),
            ]);

        $name = $response->json('data.name');

        if ($name !== null) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f-]{36}\.png$/',
                $name,
                "A PNG called {$filename} was stored as {$name}."
            );
        }

        $this->assertSame([], $this->nonImages(), 'Something on disk is servable as more than an image.');
    }

    #[DataProvider('hostileNames')]
    public function test_the_avatar_upload_stores_images_under_an_image_extension(string $filename): void
    {
        Storage::fake('public');

        // Not staff — anybody who registered can reach this one.
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)->post('/account/avatar', [
            'avatar' => new UploadedFile($this->polyglot(), $filename, 'image/png', null, true),
        ]);

        $avatar = $customer->fresh()->avatar;

        if ($avatar !== null) {
            $this->assertStringEndsWith('.png', $avatar, "An avatar was stored as {$avatar}.");
        }

        $this->assertSame([], $this->nonImages(), 'Something on disk is servable as more than an image.');
    }

    /** Whatever is on the public disk that a browser would not treat as an image. */
    private function nonImages(): array
    {
        return array_values(array_filter(
            Storage::disk('public')->allFiles(),
            fn (string $file) => ! preg_match('/\.(png|jpg|webp|gif)$/', $file),
        ));
    }

    /** The honest path still works, and a GIF is still stored as a GIF. */
    public function test_an_ordinary_upload_keeps_its_own_type(): void
    {
        Storage::fake('public');

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/media', [
                'image' => new UploadedFile($this->polyglot('gif'), 'promo.gif', 'image/gif', null, true),
            ]);

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.gif$/', $response->json('data.name'));
    }

    /**
     * A GIF is fine for a product shot and not for an avatar, and the stored
     * name must follow the endpoint's own list rather than the shared table.
     */
    public function test_an_avatar_refuses_a_type_that_endpoint_does_not_accept(): void
    {
        Storage::fake('public');

        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)->post('/account/avatar', [
            'avatar' => new UploadedFile($this->polyglot('gif'), 'me.gif', 'image/gif', null, true),
        ]);

        $this->assertNull($customer->fresh()->avatar);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }
}
