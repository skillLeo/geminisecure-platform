<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Every export writes an audit entry — 12 §1
|--------------------------------------------------------------------------
|
| THE RULING: "every export writes an audit entry — actor, scope, row count,
| timestamp; cross-tenant exports additionally name the tenants included. That
| audit entry IS the trail the reason asked for."
|
| A file that has left cannot be retained, recalled or deleted by this system,
| and pretending otherwise would be the worse lie. What can be true is that the
| estate knows a file left, who took it, what was in it and when — and the
| entry is written BEFORE a byte is streamed, so an export that fails halfway
| is still on the record.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function lastExportEntry(): ?object
{
    return DB::connection('mysql')->table('audit_log')
        ->where('action', 'export.taken')
        ->orderByDesc('id')
        ->first();
}

it('records the arrears export with its actor, scope and row count, and refuses a reader', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    // Reading the arrears is `view`; taking them away as a file is `export`.
    $this->actingAs($president)
        ->get(FacilitiesFixture::url('/finance/arrears/export'))
        ->assertForbidden();

    expect(lastExportEntry())->toBeNull();

    $response = $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/arrears/export'))
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/csv');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // A UTF-8 BOM, so Excel on a Jamaican strata office desk reads the accents.
    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($lines[0])->toContain('Unit')
        ->and($lines[0])->toContain('Balance JMD');

    $entry = lastExportEntry();
    $after = json_decode((string) $entry->after, true);

    expect($entry)->not->toBeNull()
        ->and($entry->actor_name)->toBe($treasurer->name)
        ->and($entry->created_at)->not->toBeNull()
        ->and($after['scope'])->toBe('Arrears register, all phases')
        ->and($after['rows'])->toBe(count($lines) - 1)

        // A single-estate export does NOT name tenants: the entry's own
        // tenant_id already says which estate, and repeating it would make the
        // two look like different claims.
        ->and($after)->not->toHaveKey('tenants');

    // THE FILTERS ARE IN THE SCOPE, because a file that says "arrears" and
    // holds one phase is one somebody will read as the whole position.
    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/arrears/export').'?overdue=1')
        ->assertOk()
        ->streamedContent();

    $after = json_decode((string) lastExportEntry()->after, true);

    expect($after['scope'])->toBe('Arrears register, all phases, 90+ days only');
});

it('records the receipt export, and exports the whole sequence rather than one page', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $response = $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/receipts/export'))
        ->assertOk();

    $csv = $response->streamedContent();
    $after = json_decode((string) lastExportEntry()->after, true);

    expect($after['scope'])->toContain('Receipt register')
        ->and($after['rows'])->toBe(count(array_filter(explode("\n", trim($csv)))) - 1)
        ->and($after['file'])->toContain('receipts-');
});

it('names the estates in a cross-tenant export, and records the audit log exporting itself', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $response = $this->actingAs($director)->get('/guards/compliance/export')->assertOk();
    $response->streamedContent();

    $after = json_decode((string) lastExportEntry()->after, true);

    /*
     * THE RULING'S SECOND SENTENCE. A licence register spans estates, so the
     * entry names them — and names them from the ROWS, not from the viewer's
     * scope: the scope is what they may see, the entry records what they took.
     */
    expect($after['scope'])->toContain('PSRA licence register')
        ->and($after)->toHaveKey('tenants')
        ->and($after['tenant_count'])->toBe(count($after['tenants']));

    // The audit log exporting itself is recorded — the one act on that screen
    // that would otherwise leave no trace.
    $response = $this->actingAs($director)->get('/audit/export')->assertOk();
    $response->streamedContent();

    $entry = lastExportEntry();
    $after = json_decode((string) $entry->after, true);

    expect($after['scope'])->toContain('Access audit log')
        ->and($after)->toHaveKey('tenants')
        ->and($entry->actor_name)->toBe($director->name);
});
