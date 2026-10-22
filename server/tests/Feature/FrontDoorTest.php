<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\FrontDoor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| ONE FRONT DOOR (M11 §5.2)
|--------------------------------------------------------------------------
|
| "Log in" on the landing page takes anybody to their own console, signed in, without remembering their
| estate's address — by a single-use 60-second token redeemed on the estate's OWN host, which makes its own
| session. NO COOKIE CROSSES A HOSTNAME: the central host never holds a session for an estate account, which
| is exactly what broke the client's meeting on 23 September 2026.
|
| Every row this file makes is rolled back, as SignInLandsOnYourOwnConsoleTest's are.
|
*/

const FRONT_DOOR_ESTATE = 'frontdoorone';
const FRONT_DOOR_SECOND = 'frontdoortwo';
const FRONT_DOOR_PASSWORD = 'correct-horse-battery-staple';

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();
    // A named limiter's key is md5(name . key): what login counts for this address.
    RateLimiter::clear(md5('login'.'ip:127.0.0.1'));
    frontDoorEstate(FRONT_DOOR_ESTATE, 'Front Door One Estate');
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function frontDoorUser(Console $console, array $estates = [FRONT_DOOR_ESTATE], string $status = 'active'): User
{
    $role = Role::findOrCreate('test.front_door_'.$console->value, 'web');
    $role->forceFill(['console' => $console->value])->save();

    $user = User::factory()->create(['console' => $console->value, 'status' => $status, 'password' => bcrypt(FRONT_DOOR_PASSWORD)]);
    $user->syncRoles([$role->name]);

    if ($console === Console::Estate) {
        foreach ($estates as $estate) {
            EstateAssignment::updateOrCreate(['user_id' => $user->id, 'tenant_id' => $estate], ['role_id' => $role->id, 'is_active' => true]);
        }
    }

    return $user->fresh();
}

/**
 * An estate as the front door and the subdomain resolver see it: its central row and its domain, rolled back
 * with everything else. No estate database — nothing on the front door's path reads one.
 */
function frontDoorEstate(string $id, string $name, string $status = 'active'): void
{
    DB::table('tenants')->insert(['id' => $id, 'name' => $name, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('domains')->insert(['domain' => $id, 'tenant_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
}

function secondEstate(string $status = 'active'): void
{
    frontDoorEstate(FRONT_DOOR_SECOND, 'Front Door Two Estate', $status);
}

function estateHost(string $estate = FRONT_DOOR_ESTATE): string
{
    return 'http://'.$estate.'.'.config('app.estate_domain');
}

/** Through both steps of the front door, as a person would. */
function throughTheFrontDoor($test, User|string $who, string $password = FRONT_DOOR_PASSWORD)
{
    $email = $who instanceof User ? $who->email : $who;
    $test->post('/sign-in/email', ['email' => $email])->assertRedirect(route('front-door.show'));

    return $test->post('/sign-in', ['email' => $email, 'password' => $password]);
}

/** The token a handoff redirect carries, from its Location. */
function tokenIn(string $location): string
{
    expect($location)->toMatch('#/front-door/[A-Za-z0-9]{64}$#');

    return substr($location, -64);
}

describe('the landing page, while the client\'s own site is not built (M14: ClientSiteTest)', function () {
    beforeEach(function () {
        config(['landing.site.index' => storage_path('framework/testing/no-client-site-here.html')]);
    });

    it('is the company\'s name and Log in, and nothing about the product (M24) — and no script', function () {
        $html = $this->get('/')->assertOk()->getContent();

        expect($html)->toContain('<title>Gemini Security Limited</title>')
            ->toContain('href="'.route('front-door.show').'"')
            ->toContain('<meta name="robots" content="noindex, nofollow">')
            ->not->toContain('GeminiSecure')
            ->not->toContain(route('public.privacy'))->not->toContain(route('public.terms'))
            ->not->toContain('Google Play')->not->toContain('App Store')
            ->not->toContain('<script')
            ->not->toContain('<link rel="stylesheet"');
    });

    it('sends a signed-in Gemini account to its console', function () {
        $this->actingAs(frontDoorUser(Console::Gemini))->get('/')->assertRedirect(route('gemini.dashboard'));
    });
});

describe('the front door never says whether an account exists', function () {
    it('takes any address to the password step', function () {
        $this->post('/sign-in/email', ['email' => 'nobody-at-all@example.test'])->assertRedirect(route('front-door.show'));
        $this->get('/sign-in')->assertOk()->assertSee('You are logging in as')->assertSee('nobody-at-all@example.test');
    });

    it('answers an unknown address exactly as it answers a wrong password', function () {
        $user = frontDoorUser(Console::Estate);

        $unknown = throughTheFrontDoor($this, 'nobody-at-all@example.test');
        $wrong = throughTheFrontDoor($this, $user, 'not-the-password');

        expect($unknown->status())->toBe($wrong->status());
        $unknown->assertSessionHasErrors(['password' => 'Those credentials do not match our records.']);
        $wrong->assertSessionHasErrors(['password' => 'Those credentials do not match our records.']);
        $this->assertGuest();
    });

    it('is rate-limited on the address, as every other door is', function () {
        $user = frontDoorUser(Console::Estate);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/sign-in', ['email' => $user->email, 'password' => 'wrong-'.$i]);
        }

        $this->post('/sign-in', ['email' => $user->email, 'password' => FRONT_DOOR_PASSWORD])->assertStatus(429);
    });

    it('offers two plain choices first, and explains nothing on any step (M24 §4)', function () {
        $this->get('/sign-in')->assertOk()
            ->assertSee('Log in to your community as staff')->assertSee('Log in to your community as a resident')
            ->assertSee('href="'.route('front-door.staff').'"', false)->assertSee('href="'.route('front-door.resident').'"', false);
        $this->get('/sign-in/staff')->assertOk()->assertSee('Log in as staff')->assertSee('Email address');

        $this->post('/sign-in/email', ['email' => 'resident@example.test']);

        foreach (['/sign-in', '/sign-in/staff/forgot'] as $uri) {
            $this->get($uri)->assertOk()
                ->assertDontSee('Resident App')->assertDontSee('Guard App')->assertDontSee('console')
                ->assertDontSee('Live in a community')->assertDontSee('Get the apps');
        }
    });

    it('goes back to the staff email step from "Use a different email"', function () {
        $this->post('/sign-in/email', ['email' => 'someone@example.test']);

        $this->post('/sign-in/restart')->assertRedirect(route('front-door.staff'));
        $this->get('/sign-in')->assertSee('Log in to your community as staff');
    });

    it('resets a staff password from the same plain page, saying one sentence whatever the address', function () {
        $this->from('/sign-in/staff/forgot')->post('/forgot-password', ['email' => 'nobody-at-all@example.test'])->assertRedirect('/sign-in/staff/forgot');

        $this->get('/sign-in/staff/forgot')->assertOk()->assertSee('If that address belongs to a staff account, we have emailed it a link');
    });
});

describe('an estate account is handed to its estate, signed in there and nowhere else', function () {
    it('redirects to the estate\'s own host with a token, and holds no session here', function () {
        $user = frontDoorUser(Console::Estate);

        $response = throughTheFrontDoor($this, $user)->assertStatus(303);

        expect($response->headers->get('Location'))->toStartWith(estateHost().'/front-door/')
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
        $this->assertGuest();
    });

    it('is signed in on the estate\'s host when the estate redeems the token, and lands on its home', function () {
        $user = frontDoorUser(Console::Estate);
        $token = tokenIn(throughTheFrontDoor($this, $user)->headers->get('Location'));

        $this->get(estateHost().'/front-door/'.$token)->assertStatus(303)->assertRedirect(estateHost());
        $this->assertAuthenticatedAs($user);
    });

    it('never shares a cookie between hosts: the session cookie is host-only on both', function () {
        $user = frontDoorUser(Console::Estate);
        $central = throughTheFrontDoor($this, $user);
        $estate = $this->get(estateHost().'/front-door/'.tokenIn($central->headers->get('Location')));

        expect(config('session.domain'))->toBeNull();

        foreach ([$central, $estate] as $response) {
            foreach ($response->headers->getCookies() as $cookie) {
                expect($cookie->getDomain())->toBeNull("{$cookie->getName()} is set for a domain, so it would reach other hosts");
            }
        }
    });

    it('refuses the same token a second time — it cannot be replayed', function () {
        $user = frontDoorUser(Console::Estate);
        $token = tokenIn(throughTheFrontDoor($this, $user)->headers->get('Location'));

        $this->get(estateHost().'/front-door/'.$token)->assertRedirect(estateHost());
        auth()->logout();

        $this->get(estateHost().'/front-door/'.$token)->assertRedirect(estateHost().'/login')
            ->assertSessionHasErrors(['email' => 'That sign-in link has expired or has already been used. Sign in again.']);
        $this->assertGuest();
    });

    it('refuses a token older than 60 seconds', function () {
        $user = frontDoorUser(Console::Estate);
        $token = tokenIn(throughTheFrontDoor($this, $user)->headers->get('Location'));

        Carbon::setTestNow(Carbon::now()->addSeconds(FrontDoor::TTL_SECONDS + 1));

        try {
            $this->get(estateHost().'/front-door/'.$token)->assertRedirect(estateHost().'/login');
            $this->assertGuest();
        } finally {
            Carbon::setTestNow();
        }
    });

    it('refuses a token at an estate it was not issued for, and spends it there too', function () {
        secondEstate();
        $user = frontDoorUser(Console::Estate, [FRONT_DOOR_ESTATE, FRONT_DOOR_SECOND]);
        $token = app(FrontDoor::class)->issue($user, FRONT_DOOR_ESTATE, request());

        $this->get(estateHost(FRONT_DOOR_SECOND).'/front-door/'.$token)->assertRedirect(estateHost(FRONT_DOOR_SECOND).'/login');
        $this->get(estateHost().'/front-door/'.$token)->assertRedirect(estateHost().'/login');
        $this->assertGuest();
    });

    it('stores only a hash of the token, bound to one account and one estate', function () {
        $user = frontDoorUser(Console::Estate);
        $token = tokenIn(throughTheFrontDoor($this, $user)->headers->get('Location'));

        $row = DB::table('front_door_tokens')->where('user_id', $user->id)->first();

        expect($row->token_hash)->toBe(hash('sha256', $token))
            ->and(DB::table('front_door_tokens')->where('token_hash', $token)->exists())->toBeFalse()
            ->and($row->tenant_id)->toBe(FRONT_DOOR_ESTATE);
    });

    it('audits every handoff — issued, redeemed and refused — with who, which estate and from what address', function () {
        $user = frontDoorUser(Console::Estate);
        $token = tokenIn(throughTheFrontDoor($this, $user)->headers->get('Location'));
        $this->get(estateHost().'/front-door/'.$token);
        auth()->logout();
        $this->get(estateHost().'/front-door/'.$token);

        $entries = AuditEntry::query()->where('entity_type', 'User')->where('entity_id', (string) $user->id)->where('action', 'like', 'session.front_door_%')->orderBy('id')->get();

        expect($entries->pluck('action')->all())->toBe(['session.front_door_issued', 'session.front_door_redeemed', 'session.front_door_refused']);

        foreach ($entries as $entry) {
            expect($entry->tenant_id)->toBe(FRONT_DOOR_ESTATE)->and($entry->ip)->toBe('127.0.0.1')->and($entry->actor_id)->toBe($user->id);
        }

        expect($entries[2]->after)->toMatchArray(['why' => 'used or expired']);
    });

    it('signs out somebody else signed in on that estate\'s host before signing the token\'s account in', function () {
        $someoneElse = frontDoorUser(Console::Estate);
        $user = frontDoorUser(Console::Estate);
        $token = tokenIn(throughTheFrontDoor($this, $user)->headers->get('Location'));

        $this->actingAs($someoneElse)->get(estateHost().'/front-door/'.$token);
        $this->assertAuthenticatedAs($user);
    });

    it('still hands over an account whose estate is suspended — a billing state, never an entry one', function () {
        DB::table('tenants')->where('id', FRONT_DOOR_ESTATE)->update(['status' => 'suspended']);
        $user = frontDoorUser(Console::Estate);

        $token = tokenIn(throughTheFrontDoor($this, $user)->headers->get('Location'));
        $this->get(estateHost().'/front-door/'.$token)->assertRedirect(estateHost());
        $this->assertAuthenticatedAs($user);
    });

    it('says why, and signs nobody in, when the account itself is suspended', function () {
        $user = frontDoorUser(Console::Estate, status: 'suspended');

        throughTheFrontDoor($this, $user)->assertRedirect()
            ->assertSessionHasErrors('password')
            ->assertSessionDoesntHaveErrors(['password' => 'Those credentials do not match our records.']);
        $this->assertGuest();
        expect(DB::table('front_door_tokens')->where('user_id', $user->id)->exists())->toBeFalse();
    });
});

describe('more than one estate, and the Gemini console', function () {
    it('shows an account in two estates a chooser, and hands it to the one it chose', function () {
        secondEstate();
        $user = frontDoorUser(Console::Estate, [FRONT_DOOR_ESTATE, FRONT_DOOR_SECOND]);

        throughTheFrontDoor($this, $user)->assertRedirect(route('front-door.choose'));
        $this->get('/sign-in/choose')->assertOk()->assertSee('Choose your community')->assertSee('Front Door Two Estate');

        $away = $this->post('/sign-in/choose', ['estate' => FRONT_DOOR_SECOND])->assertStatus(303);
        expect($away->headers->get('Location'))->toStartWith(estateHost(FRONT_DOOR_SECOND).'/front-door/');
        $this->assertGuest();
    });

    it('refuses a choice the account does not hold', function () {
        secondEstate();
        $user = frontDoorUser(Console::Estate, [FRONT_DOOR_ESTATE, FRONT_DOOR_SECOND]);
        throughTheFrontDoor($this, $user);

        $this->post('/sign-in/choose', ['estate' => 'someone-elses'])->assertRedirect(route('front-door.choose'));
        expect(DB::table('front_door_tokens')->where('user_id', $user->id)->exists())->toBeFalse();
    });

    it('keeps a Gemini account here, where its console is, and sends it on to its second factor — no token', function () {
        $user = frontDoorUser(Console::Gemini);

        throughTheFrontDoor($this, $user)->assertRedirect(route('two-factor.show'));
        $this->assertGuest();
        expect(DB::table('front_door_tokens')->where('user_id', $user->id)->exists())->toBeFalse();
    });

    it('signs a Gemini account in here directly where the second factor is off', function () {
        config(['auth.gemini_two_factor' => false]);
        $user = frontDoorUser(Console::Gemini);

        throughTheFrontDoor($this, $user)->assertRedirect(route('gemini.dashboard'));
        $this->assertAuthenticatedAs($user);
    });
});

describe('the consoles\' own doors', function () {
    it('hands an estate account that signs in at the CENTRAL /login to its estate, instead of making a session here', function () {
        $user = frontDoorUser(Console::Estate);

        $response = $this->post('/login', ['email' => $user->email, 'password' => FRONT_DOOR_PASSWORD])->assertStatus(303);

        expect($response->headers->get('Location'))->toStartWith(estateHost().'/front-door/');
        $this->assertGuest();
    });

    it('signs an estate account in as before at its OWN estate\'s door', function () {
        $user = frontDoorUser(Console::Estate);

        $this->post(estateHost().'/login', ['email' => $user->email, 'password' => FRONT_DOOR_PASSWORD])->assertRedirectContains(FRONT_DOOR_ESTATE);
        $this->assertAuthenticatedAs($user);
        expect(DB::table('front_door_tokens')->where('user_id', $user->id)->exists())->toBeFalse();
    });

    it('hands an estate account found holding a central session to its estate, signed in', function () {
        $user = frontDoorUser(Console::Estate);

        $response = $this->actingAs($user)->get('/dashboard')->assertStatus(303);
        expect($response->headers->get('Location'))->toStartWith(estateHost().'/front-door/');
        $this->assertGuest();
    });
});
