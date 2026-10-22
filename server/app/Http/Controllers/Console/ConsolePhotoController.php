<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Console\ConsolePhotos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in person's own profile picture, on whichever console they are in (M12 §4.8). The same three routes
 * are on the Gemini console and on every estate's door; each only ever reads or writes the person signed in.
 */
class ConsolePhotoController extends Controller
{
    public function __construct(private readonly ConsolePhotos $photos, private readonly AuditLogger $audit) {}

    public function show(Request $request): Response
    {
        $user = $this->me($request);
        $path = $user->getAttribute('photo_path');

        abort_unless(is_string($path) && $path !== '' && Storage::disk(ConsolePhotos::DISK)->exists($path), 404);

        // Versioned by `?v=`, so a year's cache is safe: a new picture is a new address. Private: never a shared cache.
        return Storage::disk(ConsolePhotos::DISK)->response($path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->me($request);

        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:'.implode(',', ConsolePhotos::MIMES), 'max:'.ConsolePhotos::MAX_KB, 'dimensions:max_width=2048,max_height=2048'],
        ], [
            'photo.required' => 'Choose a picture first.',
            'photo.image' => 'That file is not a picture. Choose a JPEG, PNG or WebP image.',
            'photo.mimes' => 'Choose a JPEG, PNG or WebP image.',
            'photo.max' => 'That picture is larger than 5 MB. Choose a smaller one.',
            'photo.dimensions' => 'That picture could not be read, or is more than 2,048 pixels on a side. Choose another.',
        ]);

        $this->photos->store($user, $request->file('photo'));
        $this->audit->record(action: 'account.photo_changed', entityType: 'User', entityId: (string) $user->id, after: ['version' => ConsolePhotos::version($user)]);

        return back()->with('success', 'Your profile picture is saved.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $this->me($request);

        $this->photos->remove($user);
        $this->audit->record(action: 'account.photo_removed', entityType: 'User', entityId: (string) $user->id);

        return back()->with('success', 'Your profile picture is removed. Your initials show instead.');
    }

    private function me(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
