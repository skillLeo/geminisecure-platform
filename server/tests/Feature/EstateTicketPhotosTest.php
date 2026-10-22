<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
| The photos a resident attaches from the Resident App, on the ticket page (7 October). They reached the server — the
| estate's private disk and `ticket_media` — and the console showed them nowhere. Now the ticket page lists them, and
| each one is served to that page's readers and nobody else, and only as a photo of its own ticket.
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('shows the resident\'s photos on the ticket page and serves each one only as a photo of its own ticket', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $ticket = FacilitiesFixture::ticket(1042);

    // A real JPEG's bytes, on the estate's own disk, exactly where the Resident App's upload puts one.
    $bytes = (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    $path = 'ticket-media/'.$ticket->id.'/'.hash('sha256', $bytes).'.jpg';
    FacilitiesFixture::platform()->run(fn () => Storage::disk('local')->put($path, $bytes));

    FacilitiesFixture::boot();
    $id = DB::connection('tenant')->table('ticket_media')->insertGetId([
        'maintenance_ticket_id' => $ticket->id,
        'path' => $path,
        'filename' => 'request-photo-1.jpg',
        'content_type' => 'image/jpeg',
        'bytes' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'uploaded_by_account_id' => null,
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    try {
        $this->withoutVite()->actingAs($manager)
            ->get(FacilitiesFixture::url('/facilities/maintenance/1042'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Estate/Facilities/Ticket')
                ->has('photos', 1)
                ->where('photos.0.id', $id)
                ->where('photos.0.is_image', true)
                ->where('photos.0.url', '/facilities/maintenance/1042/photos/'.$id));

        $photo = $this->actingAs($manager)->get(FacilitiesFixture::url('/facilities/maintenance/1042/photos/'.$id));
        $photo->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        expect($photo->streamedContent())->toBe($bytes)
            ->and((string) $photo->headers->get('Cache-Control'))->toContain('private');

        // Another ticket's address for the same photo is no photo at all.
        $this->actingAs($manager)->get(FacilitiesFixture::url('/facilities/maintenance/1041/photos/'.$id))->assertNotFound();

        // Nor is it there for anybody who is not signed in.
        app('auth')->forgetGuards();
        $this->get(FacilitiesFixture::url('/facilities/maintenance/1042/photos/'.$id))->assertRedirect();
    } finally {
        FacilitiesFixture::boot();
        DB::connection('tenant')->table('ticket_media')->where('id', $id)->delete();
        FacilitiesFixture::platform()->run(fn () => Storage::disk('local')->delete($path));
    }
});

it('draws no photo strip on a ticket nobody photographed', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $this->withoutVite()->actingAs($manager)
        ->get(FacilitiesFixture::url('/facilities/maintenance/1041'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('photos', 0));
});
