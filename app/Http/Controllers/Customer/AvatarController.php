<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A customer's profile picture.
 *
 * users.avatar has existed since the first migration and nothing ever wrote to
 * it — every screen fell back to drawing the initial of the customer's name.
 */
class AvatarController extends Controller
{
    /** SVG is excluded deliberately: it can carry script and is served inline. */
    private const ALLOWED_MIMES = ['jpeg', 'jpg', 'png', 'webp'];

    private const MAX_KILOBYTES = 3072; // 3 MB

    private const FOLDER = 'uploads/avatars';

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:'.implode(',', self::ALLOWED_MIMES),
                'max:'.self::MAX_KILOBYTES,
            ],
        ], [
            'avatar.required' => 'Please choose a picture to upload.',
            'avatar.image' => 'That file is not an image.',
            'avatar.mimes' => 'Pictures must be JPG, PNG or WebP.',
            'avatar.max' => 'Pictures must be under '.(self::MAX_KILOBYTES / 1024).'MB.',
        ]);

        $user = Auth::user();

        /** @var UploadedFile $file */
        $file = $validated['avatar'];

        // Never reuse the client's filename: it can carry path traversal or a
        // double extension such as "shell.php.jpg".
        $name = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension() ?: 'jpg');

        $path = $file->storeAs(self::FOLDER, $name, 'public');

        if (! $path) {
            return back()->with('error', 'We could not save that picture. Please try again.');
        }

        $this->forgetPrevious($user->avatar);

        $user->forceFill(['avatar' => '/storage/'.$path])->save();

        return back()->with('success', 'Profile picture updated.');
    }

    public function destroy(): RedirectResponse
    {
        $user = Auth::user();

        $this->forgetPrevious($user->avatar);

        $user->forceFill(['avatar' => null])->save();

        return back()->with('success', 'Profile picture removed.');
    }

    /**
     * Delete the file the customer is replacing, so changing a picture ten
     * times does not leave ten files behind.
     *
     * Only touches our own upload folder — an avatar pointing at a remote URL,
     * or anywhere else on the disk, is left alone.
     */
    private function forgetPrevious(?string $avatar): void
    {
        if (! $avatar) {
            return;
        }

        $relative = ltrim(Str::after($avatar, '/storage/'), '/');

        if (! str_starts_with($relative, self::FOLDER.'/')) {
            return;
        }

        Storage::disk('public')->delete($relative);
    }
}
