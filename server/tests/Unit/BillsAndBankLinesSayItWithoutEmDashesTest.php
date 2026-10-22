<?php

declare(strict_types=1);

use App\Models\Estate\Bill;

/*
|--------------------------------------------------------------------------
| The bills and the bank statement say it without a dash (Law 3, seen on the dev server at 1440 px, 12 Oct)
|--------------------------------------------------------------------------
|
| The Bills page's Reference column read "Ticket #1041 — Pool filter fault" and "Common area electricity — August", and
| the bank reconciliation's statement read "POS DEP — MAINT FEES": the wireframe's own text, seeded as written, in rows
| that live databases already hold. No em dash in anything a person reads (M53 Law 3), so the middot the console uses
| for a line's second clause (`MaintenanceTicket::label()`, "Ticket #1063 · Gate light out") is what these say too:
| written so by the seeder, and read so from a row that was written the old way.
|
*/

it('reads a bill\'s reference with a middot, from a row written the old way too', function (): void {
    $ticket = (new Bill)->forceFill(['ticket_label' => 'Ticket #1041 — Pool filter fault', 'description' => 'Pool filter fault']);
    $plain = (new Bill)->forceFill(['ticket_label' => null, 'description' => 'Common area electricity — August']);
    $clean = (new Bill)->forceFill(['ticket_label' => 'Ticket #1063 · Gate light out', 'description' => 'Gate light out']);

    expect($ticket->referenceLabel())->toBe('Ticket #1041 · Pool filter fault')
        ->and($plain->referenceLabel())->toBe('Common area electricity · August')
        ->and($clean->referenceLabel())->toBe('Ticket #1063 · Gate light out');
});

it('reads a statement line\'s words with a middot', function (): void {
    expect(Bill::plain('POS DEP — MAINT FEES'))->toBe('POS DEP · MAINT FEES')
        ->and(Bill::plain('SALARY BATCH, STAFF'))->toBe('SALARY BATCH, STAFF');

    $service = (string) file_get_contents(dirname(__DIR__, 2).'/app/Services/Estate/Payables.php');

    expect(str_contains($service, "'description' => Bill::plain(\$line->description)"))->toBeTrue('the reconciliation board draws the bank\'s words as they are stored');
});

it('seeds no dash into a bill or a statement line', function (): void {
    $seeder = (string) file_get_contents(dirname(__DIR__, 2).'/database/seeders/Estate/PayablesSeeder.php');
    $dashed = [];

    foreach (explode("\n", $seeder) as $line) {
        if (preg_match('/^\s*\[\'(vendor|day)\' =>/', $line) === 1 && str_contains($line, ' — ')) {
            $dashed[] = trim($line);
        }
    }

    expect($dashed)->toBe([], "Seeded rows with a dash:\n".implode("\n", $dashed));
});
