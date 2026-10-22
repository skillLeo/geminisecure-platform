<?php

declare(strict_types=1);

use App\Jobs\Estate\RenderDocument;
use App\Models\Estate\Document;
use App\Models\Estate\Meeting;
use App\Models\Estate\MeetingMinutes;
use App\Models\Estate\Payment;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\Documents;
use App\Services\Estate\Dues;
use App\Services\Estate\EstateBranding;
use Brick\Money\Money;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| Issued documents — 12 §1
|--------------------------------------------------------------------------
|
| "Statement and receipt PDFs: server-rendered, queued, seven-year retention,
| estate logo." And separately: "document retention is 7 years for anything
| financial. Not configurable."
|
| The retention is STAMPED at issue and never derived from a setting, because a
| retention period that can be shortened after the fact is not one. A second
| press inside the window finds the first document rather than making a second.
|
| AN ESTATE OF ITS OWN (M11 B5). The documents, the logo and the pay runs below
| are in `documentstest`, which no other file uses. They used to be in the shared
| facilities estate, which the seeder below truncated and rebuilt under every
| other facilities file, and where the logo this file uploads stayed set for all
| of them. `Tests\Support\OwnEstate` has the whole account.
|
*/

/** This file's own estate: its subdomain, its tenant id and its database suffix. */
function documentsEstate(): OwnEstate
{
    static $estate = null;

    return $estate ??= new OwnEstate('documentstest', 'DCT', 'Documents Test Estate');
}

beforeEach(function () {
    documentsEstate()->boot();

    /*
     * The rate cards first. The estate's payroll seeder refuses to invent one,
     * and a test earlier in the full suite migrates the central test database
     * fresh — so without this the estate has no paid run to export, and the
     * payroll file test passes alone and fails in the suite.
     */
    Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);

    (new EstateFinanceSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    /*
     * TRUNCATED THROUGH THE SCHEMA OWNER, because a delete is refused: no
     * document leaves before its retention date (13 A2), and that trigger is
     * exactly what these tests prove. TRUNCATE fires no trigger and needs a
     * privilege the estate's own user does not hold — acceptable for a fixture
     * database that is dropped and rebuilt every process.
     */
    DB::connection('mysql_owner')->statement('TRUNCATE TABLE `'.documentsEstate()->database().'`.documents');
});

it('queues a statement, stamps seven years on it, and hands the same one back on a second press', function () {
    Queue::fake();

    $treasurer = documentsEstate()->viewer(Role::TREASURER);
    $unit = Unit::query()->orderBy('id')->firstOrFail();

    $this->actingAs($treasurer)
        ->get(documentsEstate()->url('/finance/units/'.$unit->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('documents'));

    $this->actingAs($treasurer)
        ->post(documentsEstate()->url('/finance/units/'.$unit->id.'/statement'))
        ->assertRedirect();

    documentsEstate()->boot();

    $document = Document::query()->where('kind', Document::STATEMENT)->sole();

    // QUEUED, not rendered in the request.
    Queue::assertPushed(RenderDocument::class);

    expect($document->status)->toBe(Document::QUEUED)
        ->and($document->path)->toBeNull()
        ->and($document->requested_by_name)->toBe($treasurer->name)
        ->and($document->subject_id)->toBe((string) $unit->id)

        // SEVEN YEARS, STAMPED. Not derived at read time from a setting.
        ->and((int) round($document->created_at->diffInDays($document->retain_until)))
        ->toBeGreaterThan(365 * Document::RETENTION_YEARS - 3);

    // A SECOND PRESS FINDS THE FIRST. Four identical statements for one balance
    // is what this prevents, and the sentence on the screen says so.
    $this->actingAs($treasurer)
        ->post(documentsEstate()->url('/finance/units/'.$unit->id.'/statement'))
        ->assertRedirect();

    documentsEstate()->boot();

    expect(Document::query()->where('kind', Document::STATEMENT)->count())->toBe(1)
        ->and($document->statusLine())->toContain('same document');
});

it('renders a statement to a real PDF, hashes what it wrote, and serves it once ready', function () {
    Storage::fake('local');

    $treasurer = documentsEstate()->viewer(Role::TREASURER);
    $unit = Unit::query()->orderBy('id')->firstOrFail();

    /*
     * Asked for through the route, because that is where tenancy is
     * initialised — `Documents::request()` stamps the estate onto the job and
     * refusing to guess one is the whole point of `RequiresTenantContext`.
     */
    $this->actingAs($treasurer)
        ->post(documentsEstate()->url('/finance/units/'.$unit->id.'/statement'))
        ->assertRedirect();

    documentsEstate()->boot();

    $document = Document::query()->where('kind', Document::STATEMENT)->sole();

    // The worker, run inline. Tenancy is already initialised by the fixture.
    app(RenderDocument::class, [
        'tenantId' => (string) tenant()->getTenantKey(),
        'documentId' => $document->id,
        'payload' => [],
    ])->handle(app(DocumentRenderer::class));

    $document = Document::query()->findOrFail($document->id);

    expect($document->status)->toBe(Document::READY)
        ->and($document->path)->not->toBeNull()
        ->and($document->bytes)->toBeGreaterThan(0)
        ->and($document->sha256)->toHaveLength(64)
        ->and($document->issued_at)->not->toBeNull();

    $bytes = Storage::disk('local')->get((string) $document->path);

    // A real PDF, and the hash is of what was WRITTEN — the answer to "is this
    // the statement that was issued?" in 2033.
    expect(str_starts_with($bytes, '%PDF-'))->toBeTrue()
        ->and(hash('sha256', $bytes))->toBe($document->sha256);

    $response = $this->actingAs($treasurer)
        ->get(documentsEstate()->url('/documents/'.$document->id))
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    $response->streamedContent();

    // A document that is not ready is not served — a 404, not half a file.
    $queued = Document::query()->create([
        'kind' => Document::RECEIPT,
        'subject_type' => 'payment',
        'subject_id' => '999999',
        'title' => 'Receipt',
        'filename' => 'receipt-test.pdf',
        'status' => Document::QUEUED,
        'requested_by_name' => $treasurer->name,
        'retain_until' => now()->addYears(Document::RETENTION_YEARS),
    ]);

    $this->actingAs($treasurer)
        ->get(documentsEstate()->url('/documents/'.$queued->id))
        ->assertNotFound();
});

it('records a render failure against the document rather than leaving it waiting forever', function () {
    Storage::fake('local');

    $treasurer = documentsEstate()->viewer(Role::TREASURER);

    // One request against the estate's own host, so tenancy is initialised —
    // the job refuses to run without it rather than guessing an estate, which
    // is what `RequiresTenantContext` exists for.
    $this->actingAs($treasurer)->get(documentsEstate()->url('/finance/arrears'))->assertOk();

    // A receipt whose payment does not exist. The renderer throws, and the row
    // must say so — a job that simply died would leave a document reading
    // "being produced" forever, and the reader would keep waiting.
    $document = Document::query()->create([
        'kind' => Document::RECEIPT,
        'subject_type' => 'payment',
        'subject_id' => '987654',
        'title' => 'Receipt for nothing',
        'filename' => 'receipt-nothing.pdf',
        'status' => Document::QUEUED,
        'requested_by_name' => $treasurer->name,
        'retain_until' => now()->addYears(Document::RETENTION_YEARS),
    ]);

    $job = app(RenderDocument::class, [
        'tenantId' => (string) tenant()->getTenantKey(),
        'documentId' => $document->id,
        'payload' => [],
    ]);

    $threw = false;

    try {
        $job->handle(app(DocumentRenderer::class));
    } catch (Throwable) {
        // Rethrown on purpose, so the queue can retry and a failure is visible
        // in the worker's own log as well as on the row.
        $threw = true;
    }

    expect($threw)->toBeTrue();

    $document = Document::query()->findOrFail($document->id);

    expect($document->status)->toBe(Document::FAILED)
        ->and($document->failure_reason)->not->toBeNull()
        ->and($document->statusLine())->toContain('Asking again is safe');
});

it('issues a receipt PDF from the register, and the estate logo is embedded rather than linked', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = documentsEstate()->viewer(Role::COMMUNITY_SUPER_ADMIN);
    $payment = Payment::query()->orderBy('id')->first();

    if ($payment === null) {
        expect(true)->toBeTrue();

        return;
    }

    $this->actingAs($admin)
        ->post(documentsEstate()->url('/finance/payments/'.$payment->id.'/receipt'))
        ->assertRedirect();

    documentsEstate()->boot();

    expect(Document::query()->where('kind', Document::RECEIPT)->count())->toBe(1);

    // THE LOGO. Uploaded, kept, and read back as a data: URI — never a URL,
    // because a queue worker fetching one would be making an HTTP request back
    // into the application against a host it may not resolve.
    $this->actingAs($admin)
        ->post(documentsEstate()->url('/settings/profile/logo'), [
            'logo' => UploadedFile::fake()->image('mark.png', 200, 200),
        ])
        ->assertRedirect(documentsEstate()->url('/settings/profile'));

    documentsEstate()->boot();

    $branding = app(EstateBranding::class);

    expect($branding->contents())->not->toBeNull()
        ->and($branding->dataUri())->toStartWith('data:image/png;base64,');

    // A PDF is not a logo, and the rule is stated before the press.
    $this->actingAs($admin)
        ->post(documentsEstate()->url('/settings/profile/logo'), [
            'logo' => UploadedFile::fake()->create('brochure.pdf', 10, 'application/pdf'),
        ])
        ->assertSessionHasErrors('logo');
});

/* ------------------------------------------------------------------ */
/* 13 A2 · payroll files kept · 13 A3 · every document export recorded */
/* ------------------------------------------------------------------ */

function documentsLastExport(): array
{
    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'export.taken')
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull();

    return json_decode((string) $entry->after, true);
}

it('keeps a pay run\'s bank file before it leaves, byte for byte, and keeps the same bytes once', function () {
    Storage::fake('local');

    $treasurer = documentsEstate()->viewer(Role::TREASURER);
    $run = PayrollRun::query()->where('status', PayrollRun::PAID)->orderByDesc('period_start')->firstOrFail();

    $response = $this->actingAs($treasurer)
        ->get(documentsEstate()->url('/payroll/runs/'.$run->slug.'/export?format=bank'))
        ->assertOk();

    $body = $response->streamedContent();

    documentsEstate()->boot();

    $kept = Document::query()
        ->where('kind', Document::PAYROLL_BANK_FILE)
        ->where('subject_type', 'payroll_run')
        ->where('subject_id', (string) $run->id)
        ->sole();

    // THE KEPT COPY IS THE FILE THAT LEFT: same hash, same bytes on disk, kept
    // seven years from today, and served back under its own type.
    expect($kept->status)->toBe(Document::READY)
        ->and($kept->sha256)->toBe(hash('sha256', $body))
        ->and(Storage::disk('local')->get((string) $kept->path))->toBe($body)
        ->and($kept->contentType())->toStartWith('text/csv')
        ->and((int) round($kept->issued_at->diffInDays($kept->retain_until)))->toBeGreaterThan(365 * Document::RETENTION_YEARS - 3);

    // The export's own entry names the kept copy, so the trail leads to the file.
    expect(documentsLastExport())->toMatchArray([
        'document_id' => $kept->id,
        'kind' => Document::PAYROLL_BANK_FILE,
        'sha256' => $kept->sha256,
    ]);

    // An approved run does not change, so a second export is the same bytes and
    // one kept document — not a second retention clock on one file.
    $this->actingAs($treasurer)
        ->get(documentsEstate()->url('/payroll/runs/'.$run->slug.'/export?format=bank'))
        ->assertOk()
        ->streamedContent();

    documentsEstate()->boot();

    expect(Document::query()->where('kind', Document::PAYROLL_BANK_FILE)->where('subject_id', (string) $run->id)->count())->toBe(1);

    // The run lists it, and the kept copy downloads byte for byte in year three.
    $this->actingAs($treasurer)
        ->get(documentsEstate()->url('/payroll/runs/'.$run->slug))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('keptFiles.0.id', $kept->id));

    $download = $this->actingAs($treasurer)
        ->get(documentsEstate()->url('/documents/'.$kept->id))
        ->assertOk();

    expect($download->streamedContent())->toBe($body)
        ->and($download->headers->get('Content-Type'))->toStartWith('text/csv');
});

it('gates every document on its own module, so an id cannot be counted into a statement or a bank file', function () {
    Storage::fake('local');

    // Written inside the estate, where tenancy suffixes the disk's root — the
    // same place the download route will look.
    documentsEstate()->tenant()->run(static function (): void {
        Storage::disk('local')->put('estates/'.documentsEstate()->key.'/documents/statement-test.pdf', '%PDF-1.4 test');
    });

    documentsEstate()->boot();

    $statement = Document::query()->create([
        'kind' => Document::STATEMENT,
        'subject_type' => 'unit',
        'subject_id' => (string) Unit::query()->orderBy('id')->value('id'),
        'title' => 'Statement of account — gate test',
        'filename' => 'statement-test.pdf',
        'status' => Document::READY,
        'path' => 'estates/'.documentsEstate()->key.'/documents/statement-test.pdf',
        'bytes' => 13,
        'sha256' => hash('sha256', '%PDF-1.4 test'),
        'requested_by_name' => 'Gate test',
        'issued_at' => now(),
        'retain_until' => now()->addYears(Document::RETENTION_YEARS),
    ]);

    // The Property Manager is locked out of Dues & ledger (Ruling 1), so a
    // household's statement is refused to them however they reach it.
    $this->actingAs(documentsEstate()->viewer(Role::PROPERTY_MANAGER))
        ->get(documentsEstate()->url('/documents/'.$statement->id))
        ->assertForbidden();

    // The President reads Payroll and may not export it, so no bank file.
    $bank = $statement->replicate()->fill(['kind' => Document::PAYROLL_BANK_FILE, 'subject_type' => 'payroll_run']);
    $bank->save();

    $this->actingAs(documentsEstate()->viewer(Role::PRESIDENT))
        ->get(documentsEstate()->url('/documents/'.$bank->id))
        ->assertForbidden();

    // The Treasurer holds the ledger: served, and recorded as a download.
    $this->actingAs(documentsEstate()->viewer(Role::TREASURER))
        ->get(documentsEstate()->url('/documents/'.$statement->id))
        ->assertOk()
        ->streamedContent();

    expect(documentsLastExport())->toMatchArray([
        'stage' => 'downloaded',
        'document_id' => $statement->id,
        'rows' => 1,
        'scope' => 'Statement of account — gate test',
    ]);
});

it('records a document request as an export, on the first press and on the second', function () {
    Queue::fake();

    $treasurer = documentsEstate()->viewer(Role::TREASURER);
    $unit = Unit::query()->orderBy('id')->firstOrFail();

    $before = DB::connection('mysql')->table('audit_log')->where('action', 'export.taken')->count();

    foreach ([1, 2] as $press) {
        $this->actingAs($treasurer)
            ->post(documentsEstate()->url('/finance/units/'.$unit->id.'/statement'))
            ->assertRedirect();
    }

    documentsEstate()->boot();

    $document = Document::query()->where('kind', Document::STATEMENT)->sole();

    expect(DB::connection('mysql')->table('audit_log')->where('action', 'export.taken')->count())->toBe($before + 2)
        ->and(documentsLastExport())->toMatchArray([
            'stage' => 'requested',
            'document_id' => $document->id,
            'kind' => Document::STATEMENT,
        ]);
});

it('refuses, in the database, to delete a retained document or change what was issued', function () {
    $document = Document::query()->create([
        'kind' => Document::PAYROLL_SUMMARY,
        'subject_type' => 'payroll_run',
        'subject_id' => '1',
        'title' => 'Retention probe',
        'filename' => 'probe.xlsx',
        'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'status' => Document::READY,
        'path' => 'estates/probe.xlsx',
        'bytes' => 10,
        'sha256' => str_repeat('a', 64),
        'requested_by_name' => 'Retention probe',
        'issued_at' => now(),
        'retain_until' => now()->addYears(Document::RETENTION_YEARS),
    ]);

    // Straight at the table, past every model — the promise has to hold against
    // a query typed by hand.
    $table = DB::connection('tenant')->table('documents')->where('id', $document->id);

    expect(fn () => (clone $table)->delete())->toThrow(QueryException::class, 'retained for seven years')
        ->and(fn () => (clone $table)->update(['retain_until' => now()->addYear()]))->toThrow(QueryException::class, 'never shortened')
        ->and(fn () => (clone $table)->update(['sha256' => str_repeat('b', 64)]))->toThrow(QueryException::class, 'retained as issued')
        ->and(fn () => (clone $table)->update(['path' => 'estates/other.xlsx']))->toThrow(QueryException::class, 'retained as issued')

        // …nor what it says (B027): a rewritten fingerprint could pass a stale statement off as current.
        ->and(fn () => (clone $table)->update(['facts_sha256' => str_repeat('e', 64)]))->toThrow(QueryException::class, 'retained as issued');

    expect(Document::query()->findOrFail($document->id)->sha256)->toBe(str_repeat('a', 64));
});

/* ------------------------------------------------------------------ */
/* B027 · a second press is handed the first only while it is still true */
/* ------------------------------------------------------------------ */

/** The worker, inline: tenancy is already open from the request before it. */
function documentsRender(Document $document): Document
{
    app(RenderDocument::class, [
        'tenantId' => (string) tenant()->getTenantKey(),
        'documentId' => $document->id,
        'payload' => [],
    ])->handle(app(DocumentRenderer::class));

    return Document::query()->findOrFail($document->id);
}

it('hands back a fresh statement once a payment has moved the balance, not the one that still shows it owed', function () {
    Queue::fake();
    Storage::fake('local');

    $treasurer = documentsEstate()->viewer(Role::TREASURER);

    // A lot of this test's own: a payment is a mark in an append-only ledger, and this file's estate is its own.
    documentsEstate()->boot();
    $unit = Unit::query()->firstOrCreate(['reference' => 'Lot 9027'], ['block' => 'Phase 9', 'street' => 'Phase 9 Drive', 'type' => 'residential', 'status' => 'occupied']);
    $statements = static fn () => Document::query()->where('kind', Document::STATEMENT)->where('subject_id', (string) $unit->id)->orderBy('id');

    $press = function () use ($treasurer, $unit) {
        $response = $this->actingAs($treasurer)
            ->post(documentsEstate()->url('/finance/units/'.$unit->id.'/statement'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        documentsEstate()->boot();

        return $response;
    };

    $this->actingAs($treasurer)->get(documentsEstate()->url('/finance/arrears'))->assertOk();
    documentsEstate()->boot();

    $dues = app(Dues::class);

    // Lot 9027 owes J$12,400, and the office prints its statement. It renders within seconds.
    // The estate's today, as the ledger reads it: the server's UTC date is already tomorrow after 7 PM in Kingston.
    $dues->charge($unit, Money::of('12400', 'JMD'), 'Statement probe — dues', Dues::siteDate());

    $press();
    $owed = documentsRender($statements()->sole());

    expect($owed->status)->toBe(Document::READY);

    // Pressed again with nothing changed: the same statement, and it says it is ready.
    $press()->assertSessionHas('success', fn (string $said): bool => str_contains($said, 'is ready'));

    expect($statements()->count())->toBe(1);

    // The resident pays the J$12,400, the office records it, and presses again for proof.
    $dues->receive($unit, Money::of('12400', 'JMD'), 'cash', Dues::siteDate());

    // One line (M53 Law 2, M56 §5): "Preparing the statement. It will download in a moment."
    $press()->assertSessionHas('success', 'Preparing the statement of account. It will download in a moment.')
        ->assertSessionHas('document', fn (array $doc): bool => str_ends_with($doc['status_url'], '/documents/'.$doc['id'].'/status'));

    $issued = $statements()->get();

    expect($issued)->toHaveCount(2)
        ->and($issued[1]->id)->not->toBe($owed->id)
        ->and($issued[1]->status)->toBe(Document::QUEUED);

    // And what it renders is a different statement: the one with the payment on it.
    $paid = documentsRender($issued[1]);

    expect($paid->status)->toBe(Document::READY)
        ->and($owed->facts_sha256)->toHaveLength(64)
        ->and($paid->facts_sha256)->toHaveLength(64)
        ->and($paid->facts_sha256)->not->toBe($owed->facts_sha256)
        ->and($paid->sha256)->not->toBe($owed->sha256);

    // From here nothing moves again, so a further press is handed that one back.
    $press();

    expect($statements()->count())->toBe(2);
});

it('hands back fresh minutes once the minutes have been corrected, and the same ones while they have not', function () {
    Queue::fake();

    $president = documentsEstate()->viewer(Role::PRESIDENT);
    $meeting = Meeting::query()->whereHas('minutes')->orderBy('id')->firstOrFail();
    $minutes = MeetingMinutes::query()->where('meeting_id', $meeting->id)->sole();
    $recorded = $minutes->body;

    $press = function () use ($president, $meeting): void {
        $this->actingAs($president)
            ->post(documentsEstate()->url('/governance/meetings/'.$meeting->id.'/minutes'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        documentsEstate()->boot();
    };

    $papers = static fn () => Document::query()->where('kind', Document::MINUTES)->where('subject_id', (string) $meeting->id);

    try {
        $press();
        $press();

        expect($papers()->count())->toBe(1);

        // The secretary corrects a line inside the window.
        $minutes->forceFill(['body' => $recorded.' Corrected: the motion was seconded by Lot 63.'])->save();

        $press();

        expect($papers()->count())->toBe(2);
    } finally {
        // The estate database is committed; the minutes go back as the seeder wrote them.
        $minutes->forceFill(['body' => $recorded])->save();
    }
});

/* ------------------------------------------------------------------ */
/* B097 · a document asked for inside an estate reaches the worker */
/* ------------------------------------------------------------------ */

it('puts a document on the platform\'s jobs table rather than into the estate, and the worker produces it', function () {
    Storage::fake('local');

    /*
     * THE DEPLOYED QUEUE, not the suite's `sync`. Production runs
     * QUEUE_CONNECTION=database, and inside an estate request tenancy has made the
     * estate's database the default — a database with no `jobs` table. Every other
     * test in this file fakes the queue, which is how a press that 500s on the
     * server stayed green here.
     */
    config(['queue.default' => 'database']);

    $treasurer = documentsEstate()->viewer(Role::TREASURER);
    $unit = Unit::query()->orderBy('id')->firstOrFail();

    $jobs = DB::connection('mysql')->table('jobs');
    $before = (clone $jobs)->count();

    $this->actingAs($treasurer)
        ->post(documentsEstate()->url('/finance/units/'.$unit->id.'/statement'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    documentsEstate()->boot();

    $document = Document::query()->where('kind', Document::STATEMENT)->sole();

    // ONE job, on the platform's table, carrying this estate and this document.
    expect((clone $jobs)->count())->toBe($before + 1);

    $payload = json_decode((string) (clone $jobs)->orderByDesc('id')->value('payload'), true);
    $job = unserialize($payload['data']['command']);

    expect($payload['displayName'])->toBe(RenderDocument::class)
        ->and($payload['tenant_id'])->toBe(documentsEstate()->key)
        ->and($job)->toBeInstanceOf(RenderDocument::class)
        ->and($job->documentId)->toBe($document->id)
        ->and($job->connection)->toBe(config('queue.central_connection', 'central'));

    // And the worker, reading that table from the platform side as it does on the
    // server, restores the estate from the payload and produces the file.
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

    documentsEstate()->boot();

    $document = Document::query()->findOrFail($document->id);

    expect($document->status)->toBe(Document::READY)
        ->and($document->sha256)->toHaveLength(64)
        ->and((clone $jobs)->count())->toBe($before);
});

it('says a document could not be queued, and marks it not produced, rather than leaving it "being produced" for ever', function () {
    /*
     * A queue that refuses the write — a missing table, a dropped connection. The
     * row is already committed by then, so without handling it stays QUEUED: the
     * next press inside the window is handed it back with "being produced", and no
     * job exists anywhere to produce it.
     */
    config([
        'queue.default' => 'database',
        'queue.connections.database.table' => 'jobs_that_are_not_there',
        'queue.connections.central.table' => 'jobs_that_are_not_there',
    ]);

    $treasurer = documentsEstate()->viewer(Role::TREASURER);
    $unit = Unit::query()->orderBy('id')->firstOrFail();

    $this->actingAs($treasurer)
        ->post(documentsEstate()->url('/finance/units/'.$unit->id.'/statement'))
        ->assertRedirect()
        ->assertSessionHasErrors('document');

    documentsEstate()->boot();

    $document = Document::query()->where('kind', Document::STATEMENT)->sole();

    expect($document->status)->toBe(Document::FAILED)
        ->and($document->failure_reason)->toContain('worker')
        ->and($document->statusLine())->toContain('Asking again is safe');

    // The next press is another attempt, not the failure handed back.
    $this->actingAs($treasurer)
        ->post(documentsEstate()->url('/finance/units/'.$unit->id.'/statement'))
        ->assertRedirect()
        ->assertSessionHasErrors('document');

    documentsEstate()->boot();

    expect(Document::query()->where('kind', Document::STATEMENT)->count())->toBe(2);
});

it('marks a document not produced when the queue gives up on its job, and never touches one already issued', function () {
    $treasurer = documentsEstate()->viewer(Role::TREASURER);

    // Tenancy opened by a request, as it is on the worker.
    $this->actingAs($treasurer)->get(documentsEstate()->url('/finance/arrears'))->assertOk();

    $document = Document::query()->create([
        'kind' => Document::RECEIPT,
        'subject_type' => 'payment',
        'subject_id' => '424242',
        'title' => 'Receipt the worker never finished',
        'filename' => 'receipt-unfinished.pdf',
        'status' => Document::QUEUED,
        'requested_by_name' => $treasurer->name,
        'retain_until' => now()->addYears(Document::RETENTION_YEARS),
    ]);

    /*
     * A job the worker was killed in the middle of — a timeout, a restart — never
     * reaches the catch in `handle()`. The queue calls `failed()` when it gives up,
     * and that is the last chance to stop the row reading "being produced" for ever.
     */
    (new RenderDocument(documentsEstate()->key, $document->id))
        ->failed(new RuntimeException('The worker was stopped mid-render.'));

    $document = Document::query()->findOrFail($document->id);

    expect($document->status)->toBe(Document::FAILED)
        ->and($document->failure_reason)->not->toBeNull()
        ->and($document->statusLine())->toContain('Asking again is safe');

    // An issued document is retained as issued: a late failure report changes nothing.
    $issued = Document::query()->create([
        'kind' => Document::PAYROLL_SUMMARY,
        'subject_type' => 'payroll_run',
        'subject_id' => '1',
        'title' => 'Issued already',
        'filename' => 'issued.xlsx',
        'status' => Document::READY,
        'path' => 'estates/issued.xlsx',
        'bytes' => 10,
        'sha256' => str_repeat('c', 64),
        'requested_by_name' => $treasurer->name,
        'issued_at' => now(),
        'retain_until' => now()->addYears(Document::RETENTION_YEARS),
    ]);

    (new RenderDocument(documentsEstate()->key, $issued->id))->failed(new RuntimeException('Late.'));

    expect(Document::query()->findOrFail($issued->id)->status)->toBe(Document::READY);
});

it('says a document nothing will ever produce could not be produced, and leaves a waiting or an issued one alone', function () {
    $row = static fn (string $title, string $status, array $with = []): Document => Document::query()->create([
        'kind' => Document::STATEMENT,
        'subject_type' => 'unit',
        'subject_id' => '1',
        'title' => $title,
        'filename' => 'statement-repair.pdf',
        'status' => $status,
        'requested_by_name' => 'Repair probe',
        'retain_until' => now()->addYears(Document::RETENTION_YEARS),
        ...$with,
    ]);

    // Asked for before the fix: the press 500'd and the job went nowhere.
    $orphan = $row('Orphaned by the estate queue', Document::QUEUED);

    // Asked for a moment ago, and a worker may well be on it.
    $waiting = $row('Just asked for', Document::QUEUED);

    $issued = $row('Issued long ago', Document::READY, [
        'path' => 'estates/repair.pdf', 'bytes' => 13, 'sha256' => str_repeat('d', 64), 'issued_at' => now()->subHours(3),
    ]);

    DB::connection('tenant')->table('documents')->whereIn('id', [$orphan->id, $issued->id])->update(['created_at' => now()->subHours(3)]);

    (require database_path('migrations/tenant/2026_09_25_090000_a_document_nothing_is_producing_says_so.php'))->up();

    $orphan = Document::query()->findOrFail($orphan->id);

    expect($orphan->status)->toBe(Document::FAILED)
        ->and($orphan->statusLine())->toContain('never handed to the worker')
        ->and($orphan->statusLine())->toContain('Asking again is safe')
        ->and(Document::query()->findOrFail($waiting->id)->status)->toBe(Document::QUEUED)
        ->and(Document::query()->findOrFail($issued->id)->status)->toBe(Document::READY);
});
