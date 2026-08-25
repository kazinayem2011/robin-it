<?php

namespace Tests\Feature\Customer;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Customer profile pictures.
 *
 * users.avatar had been in the schema since the first migration with nothing
 * ever writing to it, so every screen drew the initial of the customer's name.
 */
class AvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_a_customer_can_upload_a_picture(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/account/avatar', [
                'avatar' => UploadedFile::fake()->image('me.jpg', 600, 600),
            ])
            ->assertSessionHas('success');

        $user->refresh();

        $this->assertNotNull($user->avatar);
        $this->assertStringStartsWith('/storage/uploads/avatars/', $user->avatar);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $user->avatar));
    }

    /**
     * Changing a picture ten times should not leave ten files on the disk.
     */
    public function test_replacing_a_picture_deletes_the_old_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/account/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ]);
        $first = $user->fresh()->avatar;

        $this->actingAs($user)->post('/account/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg'),
        ]);
        $second = $user->fresh()->avatar;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing(str_replace('/storage/', '', $first));
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $second));
    }

    public function test_a_customer_can_remove_their_picture(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/account/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ]);
        $path = str_replace('/storage/', '', $user->fresh()->avatar);

        $this->actingAs($user)->delete('/account/avatar')->assertSessionHas('success');

        $this->assertNull($user->fresh()->avatar);
        Storage::disk('public')->assertMissing($path);
    }

    /** SVG can carry script and is served inline, so it is not an image here. */
    public function test_an_svg_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/account/avatar', [
                'avatar' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar);
    }

    public function test_a_non_image_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/account/avatar', [
                'avatar' => UploadedFile::fake()->create('payload.php', 8, 'application/x-php'),
            ])
            ->assertSessionHasErrors('avatar');
    }

    public function test_an_oversized_picture_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/account/avatar', [
                'avatar' => UploadedFile::fake()->image('huge.jpg')->size(4096),
            ])
            ->assertSessionHasErrors('avatar');
    }

    public function test_a_guest_cannot_upload(): void
    {
        $this->post('/account/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertRedirect('/login');
    }

    /**
     * The filename comes from us, never from the client — an uploaded name can
     * carry path traversal or a double extension such as "shell.php.jpg".
     */
    public function test_the_stored_name_is_not_the_uploaded_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/account/avatar', [
            'avatar' => UploadedFile::fake()->image('shell.php.jpg'),
        ]);

        $this->assertStringNotContainsString('shell', $user->fresh()->avatar);
        $this->assertStringEndsWith('.jpg', $user->fresh()->avatar);
    }
}
