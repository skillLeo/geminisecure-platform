<?php

declare(strict_types=1);

use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\StatutoryRateVersion;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Approving a guard pay run says what happened, and why when it did not (B018)
|--------------------------------------------------------------------------
|
| The approve action flashed its sentence under a session key called `flash`,
| which `HandleInertiaRequests` never shares — it shares `flash.success` and
| `flash.error` — so "Run approved" never reached any page. And the pay-run
| screen drew neither a success nor `errors.run`, so a refused approval was a
| button that did nothing visible: the refusal sentence the service wrote was
| never shown as a message.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // A verified card of this file's own, dated where no seeded card is, so the
    // run below can be approved whatever the shared database holds. In the past:
    // no run is approved against a card before it takes effect (D-234), and it was
    // dated 2040 only to stay clear of the seeded cards (rolled back after each case).
    $card = StatutoryRateVersion::query()->create([
        'label' => 'Approval feedback test card',
        'effective_from' => '2015-01-01',
        'nis_employee_bp' => 300,
        'nis_employer_bp' => 300,
        'nis_ceiling_annual_minor' => 5_000_000_00,
        'nht_employee_bp' => 200,
        'nht_employer_bp' => 300,
        'education_tax_employee_bp' => 225,
        'education_tax_employer_bp' => 350,
        'paye_bp' => 2500,
        'paye_threshold_annual_minor' => 1_800_000_00,
        'is_verified' => true,
    ]);

    $this->run = PayrollRun::query()->create([
        'reference' => 'PR-TEST-FEEDBACK',
        'period_label' => 'January 2015',
        'period_start' => '2015-01-01',
        'period_end' => '2015-01-31',
        'periods_per_year' => 12,
        'statutory_rate_version_id' => $card->id,
        'status' => 'calculated',
        'gross_minor' => 300_000_00,
        'net_minor' => 240_000_00,
        'currency' => 'JMD',
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('flashes the approval under the key the app shares, and the page receives it', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($director)
        ->from('/payroll/'.$this->run->id)
        ->post('/payroll/'.$this->run->id.'/approve', ['reconciled' => true])
        ->assertRedirect('/payroll/'.$this->run->id)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, 'Run approved'));

    expect($this->run->fresh()->status)->toBe('approved');

    // And the redirect lands on a page that is handed the sentence.
    $this->actingAs($director)
        ->followingRedirects()
        ->from('/payroll/'.$this->run->id)
        ->post('/payroll/'.$this->run->id.'/approve', ['reconciled' => true])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Payroll/Show')
            // The second press is refused, and the refusal is on the page.
            ->where('errors.run', fn (string $why): bool => str_contains($why, 'already been approved')));
});

it('hands a fresh approval\'s sentence to the pay-run page itself', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($director)
        ->followingRedirects()
        ->from('/payroll/'.$this->run->id)
        ->post('/payroll/'.$this->run->id.'/approve', ['reconciled' => true])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Payroll/Show')
            ->where('flash.success', fn (?string $flash): bool => $flash !== null && str_contains($flash, 'Run approved')));
});

it('says the approval\'s success as a toast and its refusal inside the question that asked', function () {
    /*
     * Static copy, checked against the files the way ClientMessagingTest checks
     * Message.vue: a prop test proves the page is HANDED the sentence, and only
     * the source can show where it is SAID.
     */
    $screen = (string) file_get_contents(resource_path('js/Pages/Gemini/Payroll/Show.vue'));
    $shell = (string) file_get_contents(resource_path('js/Layouts/GeminiConsole.vue'));

    // Since 12 October (Hassam: the console's messages in three kinds) the success is the Gemini shell's toast, and the
    // refusal is said inside the question that asked, which stays open: neither is a banner on the page.
    expect(str_contains($shell, "toast(flash.success, { tone: 'success' })"))->toBeTrue('the approval\'s success is said nowhere')
        ->and(str_contains($screen, ':error="approveError"'))->toBeTrue('the refusal has no place inside the question')
        ->and(preg_match('/approveError\.value = errors\.run/', $screen))->toBe(1, 'the refusal never reaches the question');
});
