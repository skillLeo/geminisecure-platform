<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HeadOfSecurityScope;

/*
|--------------------------------------------------------------------------
| No control on the Clients screens leads a role to a 403 — bug hunt C01, and
| B015 with C02
|--------------------------------------------------------------------------
|
| The platform's rule: a control a role cannot use is drawn disabled with its
| reason, never as a live link that ends on "Not available to your role". The
| client record already kept it for "View billing history". Three roles hold
| Clients to read only — the Dispatcher, the Admin Assistant, the Accountant —
| and were drawn live links anyway:
|
|   C01         "Onboard new client" on the directory      → /clients/new           (create)
|   B015 / C02  "Manage guard assignment" on a live record  → /clients/{id}/guards   (update)
|               "Message estate admin" on a live record     → /clients/{id}/message  (update)
|               "Message primary contact" while onboarding  → /clients/{id}/message  (update)
|
| The server now says, beside each control, whether the route behind it will
| let this viewer through and, when it will not, why; the pages draw the live
| link only then. Each flag is asked of the same permissions its route checks,
| so the button and the route cannot disagree — and the routes are asserted
| here beside the flags, for every role.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    HeadOfSecurityScope::seed($this);

    $this->readers = [
        Role::DISPATCHER => FacilitiesFixture::geminiViewer(Role::DISPATCHER),
        Role::ADMIN_ASSISTANT => FacilitiesFixture::geminiViewer(Role::ADMIN_ASSISTANT),
        Role::ACCOUNTANT => FacilitiesFixture::geminiViewer(Role::ACCOUNTANT),
    ];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** Every `<Link>` in a page's source whose href names this path, as written. */
function controlLinks(string $page, string $pattern): array
{
    $source = (string) file_get_contents(resource_path('js/Pages/'.$page.'.vue'));

    preg_match_all('/<Link\b[^>]*'.$pattern.'[^>]*>/', $source, $found);

    return $found[0];
}

it('tells a role that cannot take on a client why, on the directory, instead of linking it to a 403 (C01)', function () {
    foreach ($this->readers as $role => $reader) {
        $props = HeadOfSecurityScope::props($this->actingAs($reader)->get('/clients'));

        expect($props['canCreate'])->toBeFalse($role)
            ->and($props['createBlockedReason'])->toContain('Clients create access');

        $this->actingAs($reader)->get('/clients/new')->assertForbidden();
    }

    // The Head of Security holds Clients create for its sites and no Billing, which the form needs.
    $head = HeadOfSecurityScope::props($this->actingAs($this->head)->get('/clients'));

    expect($head['canCreate'])->toBeFalse()
        ->and($head['createBlockedReason'])->toContain('Billing & subscriptions');

    $this->actingAs($this->head)->get('/clients/new')->assertForbidden();

    // The Director's is live, and the form opens.
    $director = HeadOfSecurityScope::props($this->actingAs($this->director)->get('/clients'));

    expect($director['canCreate'])->toBeTrue()
        ->and($director['createBlockedReason'])->toBeNull();

    $this->actingAs($this->director)->get('/clients/new')->assertOk();

    // And the page draws the live link only on that answer.
    $links = controlLinks('Gemini/Clients/Index', 'href="\/clients\/new"');

    expect($links)->not->toBeEmpty();

    foreach ($links as $link) {
        expect($link)->toContain('v-if="canCreate"');
    }
});

it('tells a role that may only read a client why it cannot message the estate or assign its guards (B015, C02)', function () {
    foreach ([$this->mine, $this->onboarding] as $client) {
        foreach ($this->readers as $role => $reader) {
            $props = HeadOfSecurityScope::props($this->actingAs($reader)->get('/clients/'.$client));

            // toContain() takes needles, not a message, so the role rides on a check that does.
            expect($props['messageBlockedReason'])->toBeString("{$role} on {$client}")
                ->and($props['messageBlockedReason'])->toContain('Clients update access')
                ->and($props['guardsBlockedReason'])->toBeString("{$role} on {$client}")
                ->and($props['guardsBlockedReason'])->toContain('Clients update access');

            $this->actingAs($reader)->get('/clients/'.$client.'/message')->assertForbidden();
            $this->actingAs($reader)->get('/clients/'.$client.'/guards')->assertForbidden();
        }
    }

    // A role that holds Clients update gets live controls, and both screens open.
    foreach ([$this->director, $this->head] as $manager) {
        $props = HeadOfSecurityScope::props($this->actingAs($manager)->get('/clients/'.$this->mine));

        expect($props['messageBlockedReason'])->toBeNull()
            ->and($props['guardsBlockedReason'])->toBeNull();

        $this->actingAs($manager)->get('/clients/'.$this->mine.'/message')->assertOk();
        $this->actingAs($manager)->get('/clients/'.$this->mine.'/guards')->assertOk();
    }

    // And every link to either screen on the record is drawn only on that answer.
    $links = controlLinks('Gemini/Clients/Show', '\/clients\/\$\{estate\.id\}\/(?:message|guards)');

    expect($links)->toHaveCount(3);

    foreach ($links as $link) {
        expect($link)->toMatch('/v-if="(messageBlockedReason|guardsBlockedReason) === null"/');
    }
});
