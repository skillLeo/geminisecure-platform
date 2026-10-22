<?php

declare(strict_types=1);

use App\Models\Estate\Document;
use App\Models\Estate\Notice;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\Notices;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Board 32's composer says what a post did, and why one was refused (B048)
|--------------------------------------------------------------------------
|
| The composer read neither. A notice that went out was flashed under the session
| key `flash`, which no page is sent — HandleInertiaRequests shares `success` and
| `error` — so a post that worked looked like a button that did nothing. A post
| that was refused (a file over 10 MB, a kind the estate does not keep) came back
| as a validation error nothing on the page drew. And a notice that went out and
| then lost its file came back as a VALIDATION ERROR, so the composer kept its
| text as though nothing had been sent — with Post still live, in front of an
| office that had just told four hundred households something.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: every notice here goes to a phase
| no household is in, under a title of this file's own.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/**
 * Board 32's composer, as it posts.
 *
 * @return array<string, mixed>
 */
function composedNotice(string $title, ?UploadedFile $file = null): array
{
    return array_filter([
        'kind' => Notice::GENERAL,
        'title' => $title,
        'body' => 'Gate 2 is closed while the barrier arm is repaired.',
        'audience_scope' => 'phase',
        'audience_phase' => 'Phase 8',
        'attachment' => $file,
    ], static fn ($value): bool => $value !== null);
}

it('reports a notice that went out under the flash the page is actually sent', function () {
    $title = 'Composer posted '.bin2hex(random_bytes(3));

    $this->actingAs($this->admin)
        ->post(FacilitiesFixture::url('/governance/notices'), composedNotice($title))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Notice posted.');

    $this->actingAs($this->admin)
        ->get(FacilitiesFixture::url('/governance/notices'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Governance/Notices')
            ->where('flash.success', 'Notice posted.'));
});

it('refuses a file over 10 MB against the field, in the words the field note uses, and posts nothing', function () {
    $title = 'Composer oversize '.bin2hex(random_bytes(3));

    $this->actingAs($this->admin)
        ->post(FacilitiesFixture::url('/governance/notices'), composedNotice($title, UploadedFile::fake()->create('site-plan.pdf', 11_000, 'application/pdf')))
        ->assertSessionHasErrors('attachment');

    expect((string) session('errors')->first('attachment'))->toContain('10 MB');

    FacilitiesFixture::boot();

    expect(Notice::query()->where('title', $title)->exists())->toBeFalse();
});

it('refuses a kind of file the estate does not keep against the field, naming the kinds it does', function () {
    $title = 'Composer wrong kind '.bin2hex(random_bytes(3));

    $this->actingAs($this->admin)
        ->post(FacilitiesFixture::url('/governance/notices'), composedNotice($title, UploadedFile::fake()->createWithContent('minutes.zip', "PK\x03\x04".str_repeat('x', 64))))
        ->assertSessionHasErrors('attachment');

    expect((string) session('errors')->first('attachment'))->toContain('PDF')
        ->and((string) session('errors')->first('attachment'))->toContain('Word');

    FacilitiesFixture::boot();

    expect(Notice::query()->where('title', $title)->exists())->toBeFalse();
});

it('says a file PHP refused on the way in did not arrive, rather than naming a rule it never reached', function () {
    $title = 'Composer upload refused '.bin2hex(random_bytes(3));

    // What PHP hands over for a file past upload_max_filesize: a name and an error, no bytes.
    $path = tempnam(sys_get_temp_dir(), 'notice');
    file_put_contents($path, '%PDF-1.4');
    $refused = new UploadedFile($path, 'large-scan.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

    $this->actingAs($this->admin)
        ->post(FacilitiesFixture::url('/governance/notices'), composedNotice($title, $refused))
        ->assertSessionHasErrors('attachment');

    expect((string) session('errors')->first('attachment'))->toContain('did not arrive whole');

    FacilitiesFixture::boot();

    expect(Notice::query()->where('title', $title)->exists())->toBeFalse();
});

it('says a notice went out without its file as a note, not a refusal, so the composer clears and Post is not pressed twice', function () {
    // The one late refusal validation cannot shadow: the documents path failing after the notice was posted.
    app()->instance(Notices::class, new class extends Notices
    {
        public function attach(Notice $notice, string $filename, string $contents, string $contentType, User $by): Document
        {
            throw new DomainException('The document store did not answer.');
        }
    });

    $title = 'Composer lost its file '.bin2hex(random_bytes(3));
    $upload = UploadedFile::fake()->createWithContent('letter.pdf', "%PDF-1.4\n% composer ".bin2hex(random_bytes(8))."\n%%EOF\n");

    $this->actingAs($this->admin)
        ->post(FacilitiesFixture::url('/governance/notices'), composedNotice($title, $upload))
        // No validation error: Inertia treats one as a failed post and the composer keeps its text.
        ->assertSessionHasNoErrors()
        // Both notes in words a person reads, with no em dash in either (M53 Law 3; the Hub, 12 Oct).
        ->assertSessionHas('success', 'Notice posted, without its file.')
        ->assertSessionHas('error', fn (string $note): bool => str_contains($note, 'The document store did not answer.')
            && str_contains($note, 'The notice itself went out, so do not post it again.')
            && ! str_contains($note, '—'));

    FacilitiesFixture::boot();

    expect(Notice::query()->where('title', $title)->count())->toBe(1);
});
