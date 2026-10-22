<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\Role;
use App\Models\SiteEnquiry;
use App\Models\User;
use App\Notifications\SiteEnquiryReceived;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| The client's own landing page, served as the client built it (M14, D-194)
|--------------------------------------------------------------------------
|
| `/`, `/contact` and `/privacy-policy` on the root host are the page the client's own code builds (landing/ →
| public/landing). What the page LOOKS like is proved by the pixel, text and style gate (tools/landing/, M14 §7),
| against the reference captured from the client's project (landing-reference/). What is proved here is what
| Laravel adds: where the page is served and where not, the fall-back, the two forms, and that the client's files
| are the client's to the byte.
|
*/

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();

    $this->built = storage_path('framework/testing/client-site-index.html');
    @mkdir(dirname($this->built), 0777, true);
    file_put_contents($this->built, "<!doctype html>\n<html lang=\"en\"><head><title>GSL</title><script type=\"module\" crossorigin src=\"/landing/assets/index-test.js\"></script></head><body><div id=\"root\"></div></body></html>\n");
    config(['landing.site.index' => $this->built]);

    // `throttle:5,1` keys a guest by the route's domain and the address, so the two forms share one count.
    RateLimiter::clear(sha1('|127.0.0.1'));
    Notification::fake();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    @unlink($this->built);
});

function clientSiteHost(string $estate): string
{
    return 'http://'.$estate.'.'.config('app.estate_domain');
}

/** A visitor who opened the page first, as the forms' senders always have. */
function withTheSitesToken($test)
{
    $test->get('/');

    return $test;
}

describe('where the page is', function () {
    it('is `/` on the root host: the client\'s built page, not cached against a new release', function () {
        $response = $this->get('/')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        expect($response->getContent())->toContain('<title>GSL</title>')->toContain('/landing/assets/index-test.js')->toContain('<div id="root"></div>')
            ->and($response->headers->get('Cache-Control'))->toContain('no-cache');
    });

    it('is also the Apply page and the Privacy Policy, which the page routes itself', function () {
        expect($this->get('/contact')->assertOk()->getContent())->toContain('<div id="root"></div>')
            ->and($this->get('/privacy-policy')->assertOk()->getContent())->toContain('<div id="root"></div>');
    });

    it('is on the root host only: an estate\'s host has no Apply page, no Privacy Policy and none of the forms', function () {
        foreach (['/contact', '/privacy-policy', '/site/community-types'] as $path) {
            $this->get(clientSiteHost('phoenixparkvillage1').$path)->assertNotFound();
        }

        $this->post(clientSiteHost('phoenixparkvillage1').'/site/contact-support', [])->assertNotFound();
    });

    it('sends a signed-in Gemini account to its console, as `/` always has', function () {
        $role = Role::findOrCreate('test.client_site_gemini', 'web');
        $role->forceFill(['console' => Console::Gemini->value])->save();
        $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
        $user->syncRoles([$role->name]);

        $this->actingAs($user)->get('/')->assertRedirect(route('gemini.dashboard'));
    });

    it('falls back to a plain page at `/` while the site is not built, and is 404 elsewhere — never a blank page', function () {
        @unlink($this->built);

        // M24: the company's name and Log in, and nothing about the product (it used to be the M11 page).
        expect($this->get('/')->assertOk()->getContent())->toContain('href="'.route('front-door.show').'"')
            ->toContain('Gemini Security Limited')
            ->not->toContain('GeminiSecure')
            ->not->toContain('Guard App');
        $this->get('/contact')->assertNotFound();
        $this->get('/privacy-policy')->assertNotFound();
    });
});

describe('the contact form', function () {
    it('is kept, then emailed to the address the page gives, with Reply-To the sender', function () {
        withTheSitesToken($this)
            ->postJson('/site/contact-support', ['fullName' => 'Marcia Brown', 'email' => 'marcia@example.test', 'description' => 'Can you guard our townhouse complex?'])
            ->assertOk()
            ->assertJson(['message' => 'Thank you! Our team will reach out to you via email.']);

        $enquiry = SiteEnquiry::query()->latest('id')->firstOrFail();
        expect($enquiry->kind)->toBe(SiteEnquiry::CONTACT)
            ->and($enquiry->name)->toBe('Marcia Brown')
            ->and($enquiry->email)->toBe('marcia@example.test')
            ->and($enquiry->message)->toBe('Can you guard our townhouse complex?')
            ->and($enquiry->ip_hash)->toHaveLength(64)->not->toContain('127.0.0.1');

        Notification::assertSentOnDemand(SiteEnquiryReceived::class, function (SiteEnquiryReceived $n, array $channels, AnonymousNotifiable $to) use ($enquiry) {
            $mail = $n->toMail($to);

            return $to->routes['mail'] === config('landing.site.enquiries_to')
                && $n->enquiry->is($enquiry)
                && $mail->replyTo === [['marcia@example.test', 'Marcia Brown']]
                && str_contains(implode("\n", $mail->introLines), 'Can you guard our townhouse complex?');
        });
    });

    it('refuses what the page would not send, with a sentence the page shows as it is', function () {
        $answer = withTheSitesToken($this)->postJson('/site/contact-support', ['fullName' => 'M', 'email' => 'not an address', 'description' => 'x'])->assertUnprocessable();

        expect($answer->json('message'))->toBeString()->not->toBe('');
        expect(SiteEnquiry::query()->count())->toBe(0);
        Notification::assertNothingSent();
    });

    it('is behind the session and its CSRF check, as every form on this host (the framework skips the check under test)', function () {
        foreach (['site.contact-support', 'site.registration'] as $name) {
            expect(app('router')->getRoutes()->getByName($name)?->gatherMiddleware())->toContain('web');
        }
    });

    it('takes five a minute from one address, and says so on the sixth', function () {
        $client = withTheSitesToken($this);

        foreach (range(1, 5) as $i) {
            $client->postJson('/site/contact-support', ['fullName' => 'Sender '.$i, 'email' => 'sender@example.test', 'description' => 'Message '.$i])->assertOk();
        }

        $client->postJson('/site/contact-support', ['fullName' => 'Sender 6', 'email' => 'sender@example.test', 'description' => 'Message 6'])->assertStatus(429);
    });
});

describe('the Apply page', function () {
    it('offers the community types in the shape the page reads (`value`)', function () {
        $this->getJson('/site/community-types')->assertOk()->assertExactJson(['value' => config('landing.site.community_types')]);
    });

    it('keeps an application with every field, and emails it', function () {
        withTheSitesToken($this)->postJson('/site/registration', [
            'communityTypeId' => 'community',
            'name' => 'Palm Grove Estate',
            'description' => 'Forty townhouses, one gate.',
            'address' => '12 Palm Grove Road',
            'city' => 'Portmore',
            'zipCode' => 'JMACE01',
            'state' => 'St. Catherine',
            'country' => 'Jamaica',
            'firstName' => 'Desmond',
            'lastName' => 'Clarke',
            'email' => 'desmond@example.test',
            'phone' => '876-555-0101',
        ])->assertOk()->assertJson(['message' => 'Thank you! We received your application and will reach out shortly.']);

        $enquiry = SiteEnquiry::query()->latest('id')->firstOrFail();
        expect($enquiry->kind)->toBe(SiteEnquiry::APPLICATION)
            ->and($enquiry->name)->toBe('Desmond Clarke')
            ->and($enquiry->phone)->toBe('876-555-0101')
            ->and($enquiry->details)->toMatchArray(['community_name' => 'Palm Grove Estate', 'community_type' => 'Community', 'city' => 'Portmore', 'country' => 'Jamaica']);

        Notification::assertSentOnDemand(SiteEnquiryReceived::class);
    });

    it('refuses a community type it did not offer', function () {
        withTheSitesToken($this)->postJson('/site/registration', [
            'communityTypeId' => 'castle', 'name' => 'X', 'description' => 'X', 'address' => 'X', 'city' => 'X', 'zipCode' => 'X',
            'country' => 'X', 'firstName' => 'X', 'lastName' => 'X', 'email' => 'x@example.test',
        ])->assertUnprocessable();

        expect(SiteEnquiry::query()->count())->toBe(0);
    });
});

it('carries the client\'s pages, pictures and entry to the byte (M14 §0, §6)', function () {
    // SHA-256 of the files as the client supplied them (gemini-master, 26 Sep 2026). Nothing of these is ours to edit.
    //
    // THE ONE EDIT THE CLIENT ASKED FOR (M24 §6, 1 Oct 2026), and nothing else in these three files: in Contact.tsx,
    // the second number on the "Need immediate assistance?" card is now his +1 (876) 354-2119; in LandingPage.tsx and
    // PrivacyPolicy.tsx, the footer number he no longer uses is removed. His originals were 28fde875…, c0cef620… and
    // 3409a3d7…. The test below proves neither old number is left in what the site serves.
    //
    // AND THE SECOND, 4 October 2026: the client confirmed his address is gemisecja@gmail.com. In LandingPage.tsx the
    // contact section's "Support & Sales", and in PrivacyPolicy.tsx its two email links, gave another spelling; they now
    // give his. Nothing else in either file. Before it they were f985798d… and 99ad268f…. The test after the phone
    // numbers' proves no other address is the site's contact.
    $client = [
        'src/pages/LandingPage.tsx' => '26b9f1fe0650991332700df51edf81fc4da1d7fed82f2fc09d5c8a871ab5ca70',
        'src/pages/Contact.tsx' => 'f6ea246a049dae09161f05488c077b1c41d714eff8e7787a19342b9be7e72d5e',
        'src/pages/PrivacyPolicy.tsx' => '70c19187f5ca18ea4bab9ba3acf0734b6f677b40df661abb9866bba5e4195f6f',
        'src/main.tsx' => '1c2c3418ef770de722c0b414b45638bfbe8edabbc4c2b9ae29968b87c9d5d748',
        'src/index.css' => 'ec0b51a023b12169fe2c6e2ec4859df641d51e600b1741ad8d6d452847b93589',
        'index.html' => 'c0c837c765cba21eba0c52e73931a3d15c35909fc8018aa248222a440f22d41d',
        'hero.ts' => 'ce367fb072d8e5c0d6de2c58696d559c5438e1d32a66e69e83f21ab19d0af47f',
        'public/logo.png' => '54043b66627e018e13b1d0ad93d7914f09d8a286d9247548f16276ea93ee32aa',
        'public/Cover.png' => '9814b1e901d67189da4c9af474d0b7bab8ad142a1ffd6f87a100d8064e8ee6cc',
        'public/Asuransi keluarga 1.png' => '096adb1cd2e84c4b6fbff67ff4e05260de4343c9741d6071e1730d0f70921564',
        'public/man making payment using qr code PNG.png' => '123a8564ac156a958175e3669c4a1fda949031788619d5de8a91993157a05ed4',
        'public/Emergency Team Coordination.jpg' => 'cc3c3d387ebbe7f31ee8ee5ea0b292e9c46737bb74e5dbdd82ec8879a5f23502',
        'public/favicon.ico' => '54043b66627e018e13b1d0ad93d7914f09d8a286d9247548f16276ea93ee32aa',
    ];

    foreach ($client as $file => $sha) {
        expect(hash_file('sha256', base_path('landing/'.$file)))->toBe($sha, "landing/{$file} is not the client's file");
    }

    // And the browser's tab icon is the client's: the page links a /vite.svg that does not exist, so the browser
    // asks for /favicon.ico, which on the client's site is its logo.
    expect(hash_file('sha256', public_path('favicon.ico')))->toBe($client['public/favicon.ico']);
});

it('serves the client\'s phone numbers as he gave them on 1 October 2026, and neither number he took away (M24 §6)', function () {
    // The two numbers he took away, written only as digits so this file does not carry them in a readable form.
    $gone = ['876'.'382'.'9832', '876'.'354'.'2093'];
    $digits = fn (string $s): string => preg_replace('/\D+/', '', $s) ?? '';

    $sources = array_map(fn (string $f) => (string) file_get_contents(base_path('landing/src/pages/'.$f)), ['LandingPage.tsx', 'Contact.tsx', 'PrivacyPolicy.tsx']);
    $built = array_map(fn (string $f) => (string) file_get_contents($f), glob(public_path('landing/assets/*.js')) ?: []);
    expect($built)->not->toBeEmpty();

    foreach ([...$sources, ...$built] as $text) {
        // Every phone-shaped run of characters, reduced to its digits.
        preg_match_all('/\+?1?[\s(.-]*876[\s).-]*\d{3}[\s.-]*\d{4}/', $text, $found);
        foreach ($found[0] as $number) {
            expect(substr($digits($number), -10))->not->toBeIn($gone);
        }
    }

    expect($sources[1])->toContain('<p>+1 (876) 354-2119</p>');
});

it('gives one contact address, the client\'s gemisecja@gmail.com, on every page a visitor reads, and sends both forms there (4 October 2026)', function () {
    $his = 'gemisecja@gmail.com';
    // The spelling the page used to give, written in pieces so this file does not carry it in a readable form.
    $wrong = 'geminisec'.'jm@gmail.com';
    $addresses = static fn (string $text): array => preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, $m) > 0
        ? array_values(array_unique(array_map('strtolower', $m[0])))
        : [];

    // The client's three pages, as written and as built and served (public/landing): the text, and every mailto.
    $pages = ['LandingPage.tsx', 'Contact.tsx', 'PrivacyPolicy.tsx'];
    $texts = array_combine($pages, array_map(fn (string $f) => (string) file_get_contents(base_path('landing/src/pages/'.$f)), $pages));
    foreach (glob(public_path('landing/assets/*.js')) ?: [] as $file) {
        $texts['public/landing/assets/'.basename($file)] = (string) file_get_contents($file);
    }
    expect(array_filter(array_keys($texts), fn (string $k) => str_starts_with($k, 'public/')))->not->toBeEmpty();

    foreach ($texts as $where => $text) {
        expect(str_contains(strtolower($text), $wrong))->toBeFalse("{$where} gives the old spelling");
        expect(array_values(array_diff($addresses($text), [$his])))->toBe([], "{$where} gives an address that is not the client's");
    }
    expect($texts['LandingPage.tsx'])->toContain('<p className="font-semibold">'.$his.'</p>')
        ->and($texts['PrivacyPolicy.tsx'])->toContain('href="mailto:'.$his.'"');

    // Where the contact form and the Apply form are sent: his address, unless the server sets another.
    expect(config('landing.site.enquiries_to'))->toBe($his);

    // The root host's pages as Laravel serves them, the residents' app policy and terms among them: config/legal.php
    // gives those his address too since the second change of 4 October 2026, so no other address is allowed anywhere.
    foreach (['/', '/contact', '/privacy-policy', '/sign-in', '/sign-in/staff', '/sign-in/resident', '/login', '/account/delete', '/legal/privacy', '/legal/terms'] as $path) {
        $html = (string) $this->get($path)->getContent();
        expect(str_contains(strtolower($html), $wrong))->toBeFalse("{$path} gives the old spelling")
            ->and(array_values(array_diff($addresses($html), [$his])))->toBe([], "{$path} gives an address that is not the client's");
    }
});
