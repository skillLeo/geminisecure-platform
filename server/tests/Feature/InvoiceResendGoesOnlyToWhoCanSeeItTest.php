<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use App\Services\Gemini\InvoiceActions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| "Resend to client" goes to who may read the invoice, and nobody else (B012)
|--------------------------------------------------------------------------
|
| The resend used to mail the invoice total to EVERY active assignment at the
| estate: the Secretary, the Property Manager, the Treasurer, the Admin
| Assistant, the estate's Head of Security — and Gemini's own site-scoped Head
| of Security, who is assigned to the estate through the same table. Most of
| them are refused that figure in their own console, and the mail's own
| promise is "no figures a recipient cannot already see".
|
| The estate reads its Gemini invoice under Settings → Billing, behind
| `estate.settings.view`. So that permission — never a role name (D-139) — is
| who the invoice goes to, and it must be an estate account: Gemini staff are
| colleagues, not the client.
|
| A client of this file's own, inside a transaction, so nobody another file
| assigned to a shared estate can receive or not receive anything here.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->client = 'resendtest';

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->client,
        'name' => 'Resend Test Estate',
        'receipt_prefix' => 'RST',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->invoice = Invoice::query()->create([
        'tenant_id' => $this->client,
        'reference' => 'RST-INV-202609',
        'period' => 'Sep 2026',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'subtotal_minor' => 171_000_00,
        'total_minor' => 171_000_00,
        'currency' => 'JMD',
        'due_on' => '2026-09-15',
        'status' => 'issued',
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** An account holding one role, assigned to this file's client. */
function resendHolder(string $roleName, string $tenantId, Console $console = Console::Estate): User
{
    $user = User::factory()->create(['console' => $console->value, 'status' => 'active']);
    $user->syncRoles([$roleName]);

    EstateAssignment::query()->create([
        'user_id' => $user->id,
        'tenant_id' => $tenantId,
        'role_id' => Role::query()->where('name', $roleName)->firstOrFail()->id,
        'is_active' => true,
    ]);

    return $user->fresh();
}

it('mails the invoice only to the estate accounts that can open it on the estate\'s own billing screen', function () {
    Notification::fake();

    $superAdmin = resendHolder(Role::COMMUNITY_SUPER_ADMIN, $this->client);
    $president = resendHolder(Role::PRESIDENT, $this->client);
    $vicePresident = resendHolder(Role::VICE_PRESIDENT, $this->client);

    $refused = [
        'secretary' => resendHolder(Role::SECRETARY, $this->client),
        'property manager' => resendHolder(Role::PROPERTY_MANAGER, $this->client),
        'treasurer' => resendHolder(Role::TREASURER, $this->client),
        'estate admin assistant' => resendHolder(Role::ESTATE_ADMIN_ASSISTANT, $this->client),
        'estate head of security' => resendHolder(Role::ESTATE_HEAD_OF_SECURITY, $this->client),

        // Gemini's own, assigned to this site through the same table.
        'gemini head of security' => resendHolder(Role::HEAD_OF_SECURITY, $this->client, Console::Gemini),
    ];

    // The premise, checked rather than assumed: none of them can open the estate's billing.
    foreach ($refused as $who => $user) {
        expect($user->can('estate.settings.view'))->toBeFalse($who.' should not hold estate.settings.view');
    }

    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);

    $this->actingAs($accountant)
        ->post('/billing/invoices/'.$this->invoice->id.'/resend')
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    foreach ([$superAdmin, $president, $vicePresident] as $officer) {
        Notification::assertSentTo($officer, InvoiceIssued::class);
    }

    foreach ($refused as $user) {
        Notification::assertNotSentTo($user, InvoiceIssued::class);
    }

    // The record of where it went says the same thing as the mail did.
    $recipients = (string) DB::connection('mysql')->table('invoice_sends')->where('invoice_id', $this->invoice->id)->value('recipients');

    expect(collect(explode(', ', $recipients))->sort()->values()->all())
        ->toBe(collect([$superAdmin->email, $president->email, $vicePresident->email])->sort()->values()->all());
});

it('follows the permission, not the role name, and never falls back to somebody who cannot read it', function () {
    Notification::fake();

    // A role this estate made for itself, holding Settings view and nothing else.
    $reader = Role::query()->create(['name' => 'estate.resendtest_billing_reader', 'guard_name' => 'web']);
    $reader->forceFill([
        'console' => Console::Estate->value,
        'tenant_id' => $this->client,
        'label' => 'Billing reader',
        'sort' => 99,
        'scope_default' => 'all',
        'is_system' => false,
    ])->save();
    $reader->givePermissionTo('estate.settings.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $assistant = resendHolder(Role::ESTATE_ADMIN_ASSISTANT, $this->client);
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);

    // Only people who may not read it: refused, and nothing is sent to anybody.
    $this->actingAs($accountant)
        ->post('/billing/invoices/'.$this->invoice->id.'/resend')
        ->assertSessionHasErrors('invoice');

    Notification::assertNothingSent();

    expect(DB::connection('mysql')->table('invoice_sends')->where('invoice_id', $this->invoice->id)->exists())->toBeFalse();

    // The estate's own role that holds the permission is who it goes to.
    $custom = resendHolder('estate.resendtest_billing_reader', $this->client);

    $this->actingAs($accountant)
        ->post('/billing/invoices/'.$this->invoice->id.'/resend')
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($custom, InvoiceIssued::class);
    Notification::assertNotSentTo($assistant, InvoiceIssued::class);
});

/** The mail's body lines, as the recipient reads them. */
function invoiceMailLines(InvoiceIssued $mail, User $to): string
{
    return implode("\n", $mail->toMail($to)->introLines);
}

it('says in the resent mail what is still payable once credit notes are against the invoice (B080)', function () {
    Notification::fake();

    $president = resendHolder(Role::PRESIDENT, $this->client);
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    // Uncredited: the mail's total is what is due.
    $this->actingAs($director)->post('/billing/invoices/'.$this->invoice->id.'/resend')->assertSessionHasNoErrors();

    Notification::assertSentTo($president, InvoiceIssued::class, fn (InvoiceIssued $mail): bool => str_contains(invoiceMailLines($mail, $president), 'Total due: $171,000.00')
        && str_contains(invoiceMailLines($mail, $president), 'Payable by September 15, 2026.'));

    // Part credited: the total, the credit and what is payable now — never "Total due" on the whole.
    app(InvoiceActions::class)->creditNote($this->invoice->id, '21000.00', 'Two guard-months not worked', $director);
    Notification::fake();

    $this->actingAs($director)->post('/billing/invoices/'.$this->invoice->id.'/resend')->assertSessionHasNoErrors();

    Notification::assertSentTo($president, InvoiceIssued::class, function (InvoiceIssued $mail) use ($president): bool {
        $body = invoiceMailLines($mail, $president);

        return ! str_contains($body, 'Total due')
            && str_contains($body, 'Invoice total: $171,000.00')
            && str_contains($body, 'Credited: $21,000.00')
            && str_contains($body, 'Now payable: $150,000.00, by September 15, 2026.');
    });

    // Credited in full: nothing is payable, and the mail says so.
    app(InvoiceActions::class)->creditNote($this->invoice->id, '150000.00', 'Raised in error', $director);
    Notification::fake();

    $this->actingAs($director)->post('/billing/invoices/'.$this->invoice->id.'/resend')->assertSessionHasNoErrors();

    Notification::assertSentTo($president, InvoiceIssued::class, function (InvoiceIssued $mail) use ($president): bool {
        $body = invoiceMailLines($mail, $president);

        return ! str_contains($body, 'Total due')
            && ! str_contains($body, 'Payable by')
            && str_contains($body, 'Nothing is payable on it: it has been credited in full.');
    });
});

/*
| DR-074 (the defect register's Part 7): "Resend to client" emailed at the first press. A control that starts contact
| confirms first, naming who is told, counted from the server, so the invoice page carries the addresses the resend
| would mail before anybody presses it. They come from the same selection the resend itself makes, so the question
| and the send cannot disagree, and when nobody may read the invoice the page carries the service's own refusal.
*/
it('names, before the press, exactly who the resend mails, and says why it is off when nobody may read it (DR-074)', function () {
    Notification::fake();

    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);
    $page = fn () => $this->actingAs($accountant)->get('/billing/invoices/'.$this->invoice->id)->assertOk();

    // Only somebody who may not read it: nobody is named, and the page says why in the service's words.
    resendHolder(Role::SECRETARY, $this->client);

    $page()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->component('Gemini/Billing/Invoice')
        ->where('resendTo', [])
        ->where('resendRefusal', fn ($why): bool => str_starts_with((string) $why, 'Nobody at Resend Test Estate who may read its billing')));

    $superAdmin = resendHolder(Role::COMMUNITY_SUPER_ADMIN, $this->client);
    $president = resendHolder(Role::PRESIDENT, $this->client);
    $named = collect([$superAdmin->email, $president->email])->sort()->values()->all();

    $page()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->where('resendTo', fn ($to): bool => collect($to)->sort()->values()->all() === $named)
        ->where('resendRefusal', null));

    // And the resend mails exactly those the page named.
    $this->actingAs($accountant)->post('/billing/invoices/'.$this->invoice->id.'/resend')->assertSessionHasNoErrors();

    $sent = (string) DB::connection('mysql')->table('invoice_sends')->where('invoice_id', $this->invoice->id)->value('recipients');

    expect(collect(explode(', ', $sent))->sort()->values()->all())->toBe($named);
});

it('does not mail a suspended account, or one whose assignment here was withdrawn', function () {
    Notification::fake();

    $president = resendHolder(Role::PRESIDENT, $this->client);
    $suspended = resendHolder(Role::VICE_PRESIDENT, $this->client);
    $suspended->forceFill(['status' => 'suspended'])->save();

    $withdrawn = resendHolder(Role::COMMUNITY_SUPER_ADMIN, $this->client);
    EstateAssignment::query()->where('user_id', $withdrawn->id)->update(['is_active' => false]);

    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))
        ->post('/billing/invoices/'.$this->invoice->id.'/resend')
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($president, InvoiceIssued::class);
    Notification::assertNotSentTo($suspended, InvoiceIssued::class);
    Notification::assertNotSentTo($withdrawn, InvoiceIssued::class);
});
