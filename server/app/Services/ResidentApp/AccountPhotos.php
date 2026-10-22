<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Models\ResidentAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A Resident App account's profile photo (the client, 26 Sep).
 *
 * KEPT PRIVATE, IN THE ESTATE'S OWN STORAGE. `Storage::disk('local')` is rooted per estate while its tenancy is open
 * (see CommunityController::ticketMedia), so the file lives beside that estate's other resident files and nowhere
 * else, and there is no public address for it: the app reads it with its own token (`me.photo`).
 *
 * ONE PHOTO PER ACCOUNT. A new one replaces the old file; removing it, or deleting the account, deletes the file.
 * The phone has already made it small and written it out afresh with no EXIF (a photo's hidden GPS tag says where
 * somebody lives) — the same picker the maintenance photos use — so what is stored is what was chosen, no more.
 */
class AccountPhotos
{
    /** The largest photo accepted, in kilobytes. The phone sends ~100 KB; this is room, not a target. */
    public const MAX_KB = 5120;

    public const TYPES = ['image/jpeg', 'image/png', 'image/heic', 'image/heif'];

    public function store(ResidentAccount $account, UploadedFile $file): ResidentAccount
    {
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        $sha = hash('sha256', $bytes);
        $extension = match ((string) $file->getMimeType()) {
            'image/png' => 'png',
            'image/heic', 'image/heif' => 'heic',
            default => 'jpg',
        };
        $path = 'account-photos/'.$account->id.'/'.$sha.'.'.$extension;

        Storage::disk('local')->put($path, $bytes);

        $previous = $account->getAttribute('photo_path');

        $account->forceFill(['photo_path' => $path, 'photo_sha256' => $sha, 'photo_updated_at' => Carbon::now()])->save();

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }

        return $account;
    }

    public function remove(ResidentAccount $account): void
    {
        $path = $account->getAttribute('photo_path');

        if (is_string($path) && $path !== '') {
            Storage::disk('local')->delete($path);
        }

        $account->forceFill(['photo_path' => null, 'photo_sha256' => null, 'photo_updated_at' => null])->save();
    }

    /**
     * What `me.show` says about the photo: where the app fetches it (versioned, so a changed photo is a new address
     * and an unchanged one is served from the phone's cache), or null with none.
     *
     * @return array{url: string, updated_at: string|null}|null
     */
    public static function shape(ResidentAccount $account): ?array
    {
        $sha = $account->getAttribute('photo_sha256');

        if (! is_string($sha) || $sha === '') {
            return null;
        }

        $at = $account->getAttribute('photo_updated_at');

        return [
            'url' => '/api/v1/me/photo?v='.substr($sha, 0, 12),
            'updated_at' => $at === null ? null : Carbon::parse($at)->toIso8601String(),
        ];
    }
}
