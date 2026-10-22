<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\EstateSetting;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Estate\EstateBranding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The estate's logo takes the formats it says it takes (bug hunt B073)
|--------------------------------------------------------------------------
|
| Board 21 promised "PNG, JPG or SVG" and the file picker offered SVG, while
| the server's `image` rule refused every SVG — a screen claiming a format the
| server never accepts. The refusal was the right half: the logo is served
| inline from the estate's own hostname, and an SVG is a document that can
| carry script, so accepting one would be stored cross-site scripting on every
| committee member who opened the profile. So the promise is withdrawn rather
| than the refusal, and the SERVING side is made to agree: nothing is ever
| stored or served as an SVG, whatever name a file arrives with.
|
| THE FILES ARE THE ESTATE'S OWN DISK, NOT A FAKE. Tenancy suffixes the local
| disk per estate during a request, so a faked disk is not the one the upload
| writes to; each test reads and writes inside the estate, as the app does, and
| deletes what it wrote.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // The estate's own row, restored afterwards: another file reads the mark.
    $this->logoBefore = EstateSetting::current()->logo_path;
});

afterEach(function () {
    FacilitiesFixture::boot();

    $written = EstateSetting::current()->logo_path;

    if ($written !== null && $written !== $this->logoBefore) {
        logoInEstate(fn () => Storage::disk('local')->delete($written));
    }

    EstateSetting::current()->forceFill(['logo_path' => $this->logoBefore])->save();

    DB::connection('mysql')->rollBack();
});

/** Runs `$work` inside the fixture estate — its disk, its database — and points the fixture back after. */
function logoInEstate(callable $work): mixed
{
    try {
        return Tenant::query()->findOrFail(FacilitiesFixture::ESTATE)->run($work);
    } finally {
        tenancy()->end();
        FacilitiesFixture::boot();
    }
}

/** A real PNG's bytes, drawn by GD rather than typed, so every sniffer agrees what it is. */
function logoPngBytes(): string
{
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('promises only the formats the server takes, on the screen, in the rule and in the file picker', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    expect(EstateBranding::EXTENSIONS)->not->toContain('svg')
        ->and(EstateBranding::RULES)->not->toContain('SVG')
        ->and(EstateBranding::RULES)->toContain('PNG or JPG');

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/profile'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('logoRules', EstateBranding::RULES));

    // The picker offers what the rule names and nothing else.
    $screen = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/Profile.vue'));

    expect($screen)->not->toContain('image/svg+xml')
        ->and($screen)->toContain('accept="image/png,image/jpeg"');
});

it('refuses an SVG logo with a sentence that names the formats it does take, and stores nothing', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $changes = fn (): int => AuditEntry::query()->where('action', 'estate.logo_changed')->count();
    $before = $changes();

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/profile/logo'), [
            'logo' => UploadedFile::fake()->createWithContent(
                'mark.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
            ),
        ])
        ->assertSessionHasErrors('logo');

    FacilitiesFixture::boot();

    expect(EstateSetting::current()->logo_path)->toBe($this->logoBefore)
        ->and($changes())->toBe($before);

    // The refusal the service itself gives, for a caller that skipped the rule.
    $refusal = logoInEstate(function () use ($admin): ?string {
        try {
            app(EstateBranding::class)->store(
                UploadedFile::fake()->createWithContent('mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
                $admin,
            );
        } catch (DomainException $refused) {
            return $refused->getMessage();
        }

        return null;
    });

    expect($refusal)->toStartWith('A logo is a PNG or a JPG.')
        ->and(EstateSetting::current()->logo_path)->toBe($this->logoBefore)
        ->and($changes())->toBe($before);
});

it('stores and serves a real PNG as a PNG whatever name it was sent with, and never as an SVG', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    // A real upload, so the type is read from the bytes and not from the name.
    $path = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($path, logoPngBytes());

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/profile/logo'), [
            'logo' => new UploadedFile($path, 'mark.svg', null, null, true),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(FacilitiesFixture::url('/settings/profile'));

    FacilitiesFixture::boot();

    expect((string) EstateSetting::current()->logo_path)->toEndWith('.png')
        ->and(logoInEstate(fn (): ?string => app(EstateBranding::class)->dataUri()))->toStartWith('data:image/png;base64,');

    $response = $this->actingAs($admin)->get(FacilitiesFixture::url('/settings/profile/logo'));

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('never serves a stored SVG inline from the estate\'s hostname, nor embeds one in a document', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    // However one got there — a hand-set path, an import — it is not served.
    $path = 'estates/'.FacilitiesFixture::ESTATE.'/branding/logo-legacy-test.svg';

    logoInEstate(function () use ($path): void {
        Storage::disk('local')->put($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        EstateSetting::current()->forceFill(['logo_path' => $path])->save();
    });

    // The file is really there, where the estate's requests read it from.
    expect(logoInEstate(fn (): bool => Storage::disk('local')->exists($path)))->toBeTrue();

    $response = $this->actingAs($admin)->get(FacilitiesFixture::url('/settings/profile/logo'));

    expect($response->getStatusCode())->toBe(404)
        ->and((string) $response->headers->get('Content-Type'))->not->toContain('svg');

    expect(logoInEstate(fn (): ?string => app(EstateBranding::class)->dataUri()))->toBeNull()
        ->and(logoInEstate(fn (): ?string => app(EstateBranding::class)->contents()))->toBeNull();
});
