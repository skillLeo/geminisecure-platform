<?php

declare(strict_types=1);

use App\Models\Role;
use App\Services\Console\ConsolePhotos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| "Profile picture on both consoles" — M12 §4.8
|--------------------------------------------------------------------------
|
| The sidebar and the top bar drew two initials and nothing else. Now a person sets their own picture from the
| account menu of either console; it is kept once, centrally, so it follows them into every console they may enter;
| each console serves the signed-in person their own and nobody else's; a file that is not a picture is refused in
| words; and removing it brings the initials back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Storage::fake(ConsolePhotos::DISK);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** A real 1×1 JPEG: the platform has no GD, so no fake image can be drawn — and the rules read real bytes. */
function consoleJpeg(): UploadedFile
{
    $bytes = (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', true);

    return UploadedFile::fake()->createWithContent('face.jpg', $bytes);
}

it('saves a Gemini user\'s picture, serves it back to them, and names its version on every page', function () {
    $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    $this->actingAs($user)->post('/account/photo', ['photo' => consoleJpeg()])->assertRedirect()->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->photo_sha256)->toHaveLength(64)
        ->and(Storage::disk(ConsolePhotos::DISK)->exists((string) $user->photo_path))->toBeTrue()
        ->and(ConsolePhotos::version($user))->toBe(substr((string) $user->photo_sha256, 0, 12));

    $this->actingAs($user)->get('/account/photo?v='.ConsolePhotos::version($user))
        ->assertOk()
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');

    $this->actingAs($user)->get('/verify-contact')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.photo_version', ConsolePhotos::version($user)));
});

it('is the same picture on an estate\'s own door, set there or on Gemini', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $this->actingAs($president)->post(FacilitiesFixture::url('/account/photo'), ['photo' => consoleJpeg()])->assertRedirect()->assertSessionHasNoErrors();

    $president->refresh();
    // Central, not the estate's own storage: the one picture follows the person into every console.
    expect(Storage::disk(ConsolePhotos::DISK)->exists((string) $president->photo_path))->toBeTrue();

    $this->actingAs($president)->get(FacilitiesFixture::url('/account/photo'))->assertOk();
});

it('serves each person their own and nobody else\'s', function () {
    $mine = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $theirs = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($mine)->post('/account/photo', ['photo' => consoleJpeg()])->assertRedirect();

    // There is no {user} in the address: somebody else asking is answered with THEIR picture — here, none.
    app('auth')->forgetGuards();
    $this->actingAs($theirs)->get('/account/photo')->assertNotFound();

    app('auth')->forgetGuards();
    auth()->logout();
    $this->get('/account/photo')->assertRedirect();
});

it('refuses a file that is not a picture, in words, and keeps the one it had', function () {
    $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    // A REAL upload, read for what it is: a fake one takes its type from the name, and this one is named face.jpg.
    $path = tempnam(sys_get_temp_dir(), 'gs-photo');
    file_put_contents((string) $path, 'not a picture at all');
    $notAPicture = new UploadedFile((string) $path, 'face.jpg', null, null, true);

    $this->actingAs($user)
        ->from('/verify-contact')
        ->post('/account/photo', ['photo' => $notAPicture])
        ->assertSessionHasErrors(['photo' => 'That file is not a picture. Choose a JPEG, PNG or WebP image.']);

    expect($user->fresh()?->photo_sha256)->toBeNull();
});

it('keeps nothing of the original file but its picture — no EXIF, a 256-pixel square', function () {
    // A 400×300 photo carrying an EXIF block with a GPS tag, sent straight to the server (not through the page).
    $canvas = imagecreatetruecolor(400, 300);
    imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 20, 90, 160));
    ob_start();
    imagejpeg($canvas, null, 90);
    $jpeg = (string) ob_get_clean();
    $exif = "Exif\0\0".'GPSLatitude 18.0243 GPSLongitude -76.7896';
    $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);

    $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $this->actingAs($user)->post('/account/photo', ['photo' => UploadedFile::fake()->createWithContent('holiday.jpg', $withExif)])->assertSessionHasNoErrors();

    $stored = (string) Storage::disk(ConsolePhotos::DISK)->get((string) $user->fresh()?->photo_path);
    $size = getimagesizefromstring($stored);

    expect($stored)->not->toContain('Exif')->not->toContain('GPSLatitude')
        ->and([$size[0] ?? null, $size[1] ?? null])->toBe([256, 256]);
});

it('takes the picture away, and the file with it', function () {
    $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $this->actingAs($user)->post('/account/photo', ['photo' => consoleJpeg()]);
    $path = (string) $user->fresh()?->photo_path;

    $this->actingAs($user)->delete('/account/photo')->assertRedirect();

    expect($user->fresh()?->photo_path)->toBeNull()
        ->and(Storage::disk(ConsolePhotos::DISK)->exists($path))->toBeFalse();
    $this->actingAs($user)->get('/account/photo')->assertNotFound();
});
