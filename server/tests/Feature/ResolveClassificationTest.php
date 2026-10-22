<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\DuressAlert;
use App\Models\Role;
use App\Support\AlertClassification;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| RESOLVE & CLASSIFY IS A FORM, NOT A BROWSER PROMPT (24 September 2026)
|--------------------------------------------------------------------------
|
| The dispatch alert's "Resolve & classify" opened the browser's own
| window.prompt, and the free text it took WAS the classification — "asdf"
| went on the record as readily as "medical". The client called it
| unprofessional, and it was also uncountable.
|
| Now the console's own dialog offers a fixed list (AlertClassification), and
| the server holds the choice in its own column:
|
|   1  a classification is stored, audited and shown on the timeline
|   2  "pressed by mistake" and "confirmed safe" close the alert as a false alarm
|   3  no classification, or one not on the list, is refused with a sentence
|   4  a note over the limit is refused WITH A SENTENCE and the alert stays open —
|      it used to be refused silently (B115)
|   5  "Other emergency" needs a note
|   6  the screen is handed the list and the limit from the same place the server checks them
|   7  no browser dialog is left anywhere in either console
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Http::preventStrayRequests();
    Notification::fake();

    $this->dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function classifyAlert(): DuressAlert
{
    return DuressAlert::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'kind' => 'panic',
        'raised_by_name' => 'Rae Classify',
        'unit_reference' => 'Lot 735',
        'status' => 'open',
        'server_time' => now(),
        'idempotency_key' => 'classify-'.Str::random(20),
    ]);
}

function resolveAs($test, $user, DuressAlert $alert, array $body)
{
    app('auth')->forgetGuards();

    $screen = '/dispatch/alerts/'.$alert->id;
    $response = $test->actingAs($user)->from($screen)->post($screen.'/resolve', $body);

    FacilitiesFixture::boot();

    return $response;
}

it('stores the classification, audits it, and shows it on the timeline', function () {
    $alert = classifyAlert();

    resolveAs($this, $this->dispatcher, $alert, ['classification' => 'genuine_medical', 'note' => 'Ambulance called; resident conscious.'])
        ->assertSessionHasNoErrors();

    $alert->refresh();
    expect($alert->status)->toBe('resolved')
        ->and($alert->resolution_classification)->toBe('genuine_medical')
        ->and($alert->resolution_note)->toBe('Ambulance called; resident conscious.');

    $audit = AuditEntry::query()->where('action', 'alert.resolved')->where('entity_id', (string) $alert->id)->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->after['resolution_classification'] ?? null)->toBe('genuine_medical');

    app('auth')->forgetGuards();
    $this->actingAs($this->dispatcher)->get('/dispatch/alerts/'.$alert->id)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('timeline', function ($timeline) {
            $last = collect($timeline)->last();

            return $last['title'] === 'Resolved'
                && str_contains($last['meta'], 'Genuine emergency — Medical emergency')
                && str_contains($last['meta'], 'Ambulance called; resident conscious.');
        }));
    FacilitiesFixture::boot();
});

it('closes "pressed by mistake" and "confirmed safe" as false alarms', function (string $classification) {
    $alert = classifyAlert();

    resolveAs($this, $this->dispatcher, $alert, ['classification' => $classification, 'note' => 'Resident called back; all well.'])->assertSessionHasNoErrors();

    expect($alert->refresh()->status)->toBe('false_alarm')
        ->and($alert->resolution_classification)->toBe($classification)
        ->and($alert->resolution_note)->toBe('Resident called back; all well.');
})->with(['false_alarm', 'resident_safe']);

it('refuses a missing or unknown classification with a sentence, and leaves the alert open', function (array $body, string $sentence) {
    $alert = classifyAlert();

    resolveAs($this, $this->dispatcher, $alert, $body)->assertSessionHasErrors(['classification' => $sentence]);

    expect($alert->refresh()->status)->toBe('open')
        ->and($alert->resolved_at)->toBeNull();
})->with([
    'none' => [['note' => 'asdf'], 'Choose how this alert is classified.'],
    'unknown' => [['classification' => 'asdf'], 'Choose one of the listed classifications.'],
]);

it('refuses a note over the limit with a sentence, instead of silently (B115)', function () {
    $alert = classifyAlert();

    resolveAs($this, $this->dispatcher, $alert, ['classification' => 'genuine_security', 'note' => str_repeat('x', AlertClassification::NOTE_MAX + 1)])
        ->assertSessionHasErrors(['note' => 'The note can be up to '.AlertClassification::NOTE_MAX.' characters. Shorten it and resolve again.']);

    expect($alert->refresh()->status)->toBe('open');

    resolveAs($this, $this->dispatcher, $alert, ['classification' => 'genuine_security', 'note' => str_repeat('x', AlertClassification::NOTE_MAX)])
        ->assertSessionHasNoErrors();

    expect($alert->refresh()->status)->toBe('resolved');
});

it('needs a note for every classification now — nothing is optional (M18C)', function () {
    $alert = classifyAlert();

    resolveAs($this, $this->dispatcher, $alert, ['classification' => 'genuine_other', 'note' => '   '])
        ->assertSessionHasErrors('note');
    expect($alert->refresh()->status)->toBe('open');

    resolveAs($this, $this->dispatcher, $alert, ['classification' => 'genuine_other', 'note' => 'Gas leak at the clubhouse.'])
        ->assertSessionHasNoErrors();
    expect($alert->refresh()->resolution_classification)->toBe('genuine_other');

    // A drill too: the record of what happened is kept for every closed alert.
    $quiet = classifyAlert();
    resolveAs($this, $this->dispatcher, $quiet, ['classification' => 'drill'])->assertSessionHasErrors('note');
    expect($quiet->refresh()->status)->toBe('open');

    resolveAs($this, $this->dispatcher, $quiet, ['classification' => 'drill', 'note' => 'Monthly drill, as planned.'])->assertSessionHasNoErrors();
    expect($quiet->refresh()->status)->toBe('resolved');
});

it('hands the screen the list and the limit the server checks', function () {
    $alert = classifyAlert();

    app('auth')->forgetGuards();
    $this->actingAs($this->dispatcher)->get('/dispatch/alerts/'.$alert->id)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('actions.resolve_note_max', AlertClassification::NOTE_MAX)
            ->where('actions.resolve_classifications', AlertClassification::forForm())
        );
    FacilitiesFixture::boot();

    expect(collect(AlertClassification::forForm())->pluck('value')->all())->toBe(AlertClassification::keys());
});

it('leaves no browser dialog anywhere in either console', function () {
    $offenders = [];
    $root = resource_path('js');

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! in_array($file->getExtension(), ['vue', 'js', 'ts'], true)) {
            continue;
        }

        foreach (file($file->getPathname()) ?: [] as $i => $line) {
            // Comments may NAME the thing that is banned; code may not call it.
            $code = trim($line);
            if (str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                continue;
            }

            if (preg_match('/(?<![\w.$])(?:window\.)?(?:confirm|prompt|alert)\s*\(/', $line) === 1) {
                $offenders[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($i + 1).'  '.$code;
            }
        }
    }

    expect($offenders)->toBe([]);
});
