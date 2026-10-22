<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ResidentDoorController;
use App\Models\ResidentAccount;
use App\Notifications\ResidentSignInCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| "LOG IN TO YOUR COMMUNITY AS A RESIDENT" ON THE WEB (M24 §4.4, D-223)
|--------------------------------------------------------------------------
|
| The resident proves their address with the app's own six-digit code and lands on their community platform page:
| use the app for now, and the estate office's contact. Nothing on the way tells a resident's address from any
| other, and an address with no open account is never sent a code. Every row is rolled back.
|
*/

const RESIDENT_DOOR_EMAIL = 'resident.door@example.com';

beforeEach(function () {
    // The fixture's estate exists, with its own database, before anything is rolled back.
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    config(['mail.mailers.smtp.check_domains' => false]);
    Notification::fake();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function residentDoorAccount(array $over = []): ResidentAccount
{
    return ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => RESIDENT_DOOR_EMAIL,
        'full_name' => 'Marcia Door', 'resident_id' => 990001, 'unit_id' => 990001,
        'status' => ResidentAccount::ACTIVE, 'verified_at' => now(), ...$over,
    ]);
}

/** The code the page's own request emailed, read from the fake. */
function residentDoorCode(): string
{
    $code = null;

    Notification::assertSentOnDemand(ResidentSignInCode::class, function (ResidentSignInCode $n, array $channels, object $notifiable) use (&$code): bool {
        if (strtolower((string) ($notifiable->routes['mail'] ?? '')) === RESIDENT_DOOR_EMAIL) {
            $code = $n->code;
        }

        return true;
    });

    return (string) $code;
}

it('offers the resident choice an email address and nothing more', function () {
    $html = (string) $this->get('/sign-in/resident')->assertOk()->getContent();

    expect($html)->toContain('Log in as a resident')
        ->toContain('Enter the email address you signed up with')
        ->not->toContain('console')
        ->not->toMatch('/guard|officer|Resident App|Guard App/i');
});

it('answers every address with the same code step, and sends no code to an address with no account', function () {
    $this->post('/sign-in/resident', ['email' => 'nobody.here@example.com'])->assertRedirect(route('front-door.resident'));

    $this->get('/sign-in/resident')->assertOk()
        ->assertSee('Check your email. If', false)
        ->assertSee('nobody.here@example.com')
        ->assertSee('we have sent it a 6 digit code', false);

    Notification::assertNothingSent();
});

it('emails a resident the app\'s own code, saying to type it on the website', function () {
    residentDoorAccount();

    $this->post('/sign-in/resident', ['email' => 'Resident.Door@Example.com'])->assertRedirect(route('front-door.resident'));

    Notification::assertSentOnDemand(ResidentSignInCode::class, function (ResidentSignInCode $n, array $channels, object $notifiable): bool {
        $lines = implode(' ', $n->toMail($notifiable)->introLines);

        return $n->onWeb && str_contains($lines, 'Enter this code on the website') && strtolower((string) $notifiable->routes['mail']) === RESIDENT_DOOR_EMAIL;
    });
});

it('opens the community platform page on the right code: use the app, and the estate office', function () {
    residentDoorAccount();
    $this->post('/sign-in/resident', ['email' => RESIDENT_DOOR_EMAIL]);

    $this->post('/sign-in/resident/code', ['code' => residentDoorCode()])->assertRedirect(route('community'));

    $html = (string) $this->get('/community')->assertOk()->getContent();

    expect($html)->toContain('Welcome, Marcia')
        ->toContain('Your community platform')
        ->toContain('please use the GeminiSecure app on your phone')
        ->toContain('In an emergency, call 119.')
        ->toContain('Log out')
        ->not->toContain('console')
        ->not->toMatch('/guard|officer|patrol/i');
});

it('says the same three sentences for a wrong code, whether or not the address has an account', function () {
    residentDoorAccount();

    $this->post('/sign-in/resident', ['email' => RESIDENT_DOOR_EMAIL]);
    $this->post('/sign-in/resident/code', ['code' => '000000'])->assertSessionHasErrors(['code' => ResidentDoorController::WRONG]);

    $this->post('/sign-in/resident/restart');
    $this->post('/sign-in/resident', ['email' => 'nobody.here@example.com']);
    $this->post('/sign-in/resident/code', ['code' => '000000'])->assertSessionHasErrors(['code' => ResidentDoorController::WRONG]);

    // And an address with no account runs out of tries exactly as a real code does.
    for ($i = 0; $i < 3; $i++) {
        $this->post('/sign-in/resident/code', ['code' => '000000']);
    }
    $this->post('/sign-in/resident/code', ['code' => '000000'])->assertSessionHasErrors(['code' => ResidentDoorController::LOCKED]);
});

it('asks for six digits in plain words', function () {
    $this->post('/sign-in/resident', ['email' => RESIDENT_DOOR_EMAIL]);
    $this->post('/sign-in/resident/code', ['code' => '12'])->assertSessionHasErrors(['code' => 'Enter the 6 digit code from the email.']);
});

it('sends no code to an account that is closed: deleted, ended by the office, or not yet confirmed', function (array $over) {
    residentDoorAccount($over);

    $this->post('/sign-in/resident', ['email' => RESIDENT_DOOR_EMAIL]);

    Notification::assertNothingSent();
})->with([
    'deleted' => [['status' => ResidentAccount::DELETED, 'deleted_at' => now()]],
    'revoked' => [['status' => ResidentAccount::REVOKED, 'revoked_at' => now()]],
    'pending' => [['status' => ResidentAccount::PENDING, 'resident_id' => null, 'unit_id' => null]],
]);

it('keeps the community page behind the code, and logs out', function () {
    $this->get('/community')->assertRedirect(route('front-door.resident'));

    residentDoorAccount();
    $this->post('/sign-in/resident', ['email' => RESIDENT_DOOR_EMAIL]);
    $this->post('/sign-in/resident/code', ['code' => residentDoorCode()]);
    $this->get('/community')->assertOk();

    $this->post('/community/log-out')->assertRedirect(route('front-door.show'));
    $this->get('/community')->assertRedirect(route('front-door.resident'));
});

it('signs out after thirty idle minutes, as the app does', function () {
    residentDoorAccount();
    $this->post('/sign-in/resident', ['email' => RESIDENT_DOOR_EMAIL]);
    $this->post('/sign-in/resident/code', ['code' => residentDoorCode()]);

    $this->travel(ResidentDoorController::SESSION_MINUTES + 1)->minutes();

    $this->get('/community')->assertRedirect(route('front-door.resident'));
});
