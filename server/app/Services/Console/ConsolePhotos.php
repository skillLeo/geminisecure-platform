<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A console user's profile picture (M12 §4.8: "Profile picture on both consoles").
 *
 * ONE PICTURE, EVERY CONSOLE. It is kept on the central `console_photos` disk (config/filesystems.php), which an
 * estate request does not re-root, so the same person carries the same picture into the Gemini console and every
 * estate console they may enter. Private: there is no public address; each console serves the signed-in person their
 * own, and nobody else's.
 *
 * WHAT IS STORED IS DRAWN AFRESH, TWICE OVER. The page already draws the chosen photo onto a 256-pixel square. The
 * server does it again with GD (installed and checked by deploy/10-baseline.sh): the bytes are decoded, the middle
 * square is redrawn at 256 pixels and saved as a new JPEG. A re-drawn picture carries none of the original's EXIF — a
 * phone photo's GPS tag says where somebody lives — even when something other than the page sent it. Bytes that do not
 * decode as a picture are refused.
 */
class ConsolePhotos
{
    public const DISK = 'console_photos';

    /** The stored picture's side, in pixels. */
    public const SIZE = 256;

    /**
     * The largest picture accepted, in kilobytes: 5 MB (M32 F9). The page refuses a chosen photo over the same size
     * before it draws it, and then sends ~20 KB; this is the same promise kept for anything else that posts here.
     */
    public const MAX_KB = 5120;

    /** @var list<string> */
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp'];

    public function store(User $user, UploadedFile $file): User
    {
        $bytes = self::redrawn((string) file_get_contents((string) $file->getRealPath()));
        $sha = hash('sha256', $bytes);
        $path = $user->id.'/'.$sha.'.jpg';

        Storage::disk(self::DISK)->put($path, $bytes);

        $previous = $user->getAttribute('photo_path');

        $user->forceFill(['photo_path' => $path, 'photo_sha256' => $sha, 'photo_updated_at' => Carbon::now()])->save();

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk(self::DISK)->delete($previous);
        }

        return $user;
    }

    public function remove(User $user): void
    {
        $path = $user->getAttribute('photo_path');

        if (is_string($path) && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }

        $user->forceFill(['photo_path' => null, 'photo_sha256' => null, 'photo_updated_at' => null])->save();
    }

    /**
     * The middle square of the picture, redrawn at SIZE × SIZE on white and saved as a new JPEG — nothing of the
     * original file survives but its pixels.
     *
     * @throws ValidationException when the bytes are not a picture GD can read
     */
    public static function redrawn(string $bytes): string
    {
        $source = $bytes === '' ? false : @imagecreatefromstring($bytes);

        if ($source === false) {
            throw ValidationException::withMessages(['photo' => 'That picture could not be read. Choose a JPEG, PNG or WebP image.']);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($square, 0, 0, (int) imagecolorallocate($square, 255, 255, 255));
        imagecopyresampled($square, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagejpeg($square, null, 86);

        return (string) ob_get_clean();
    }

    /** The picture's version for its address (`?v=`), or null with none: the shells draw initials then. */
    public static function version(User $user): ?string
    {
        $sha = $user->getAttribute('photo_sha256');

        return is_string($sha) && $sha !== '' ? substr($sha, 0, 12) : null;
    }
}
