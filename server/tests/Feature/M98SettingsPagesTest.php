<?php

declare(strict_types=1);

use App\Models\Estate\EstateSetting;
use App\Models\Role;
use App\Services\Estate\Settings;
use App\Services\Tracking\ConsentAssurance;
use App\Services\Tracking\SiteTrackingSettings;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The estate's Settings pages, to the standard (Hassam's closing order, 12 Oct)
|--------------------------------------------------------------------------
|
| Seven pages nobody had touched: the community profile, users and roles, the permission grid, the features and the
| plan, the audit log, and the subscription and its invoices. What each is held to here is what it said wrong before:
| a control that could not be used said why only on hover; a field nobody may change was drawn as one anybody could;
| a refusal was a red banner; the words a person reads carried em dashes and paragraphs where a line would do. The
| permission values, the role definitions and the matrix's cells are not touched by any of it: the pages show the
| truth, they do not decide it.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/** The page's own props, the shell's shared ones left out: what this page itself says. */
function settingsPageProps(object $test, string $role, string $path): array
{
    $props = [];

    $test->actingAs(FacilitiesFixture::viewer($role))
        ->get(FacilitiesFixture::url($path))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    foreach (['errors', 'timeView', 'auth', 'nav', 'estateNav', 'panicWatch', 'geminiPanicWatch', 'live', 'flash', 'screenState', 'estateIds'] as $shared) {
        unset($props[$shared]);
    }

    return $props;
}

/**
 * Every string a page was sent that carries an em dash, by where it sits. The lone "—" that stands for an empty value
 * waits on Law 5's ruling (M53) and is not counted.
 *
 * @return list<string>
 */
function settingsEmDashes(array $props, string $at = ''): array
{
    $found = [];

    foreach ($props as $key => $value) {
        $where = $at === '' ? (string) $key : $at.'.'.$key;

        if (is_array($value)) {
            $found = [...$found, ...settingsEmDashes($value, $where)];
        } elseif (is_string($value) && $value !== '—' && str_contains($value, '—')) {
            $found[] = $where.': '.$value;
        }
    }

    return $found;
}

/* ---------------------------------------------------------------- 1. the community profile */

it('sends the profile with no em dash, each locked field\'s reason one short line', function (): void {
    foreach ([Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT] as $role) {
        $props = settingsPageProps($this, $role, '/settings/profile');

        expect(settingsEmDashes($props))->toBe([]);

        $locked = collect($props['groups'])->flatMap(fn (array $group): array => $group['fields'])->where('editable', false);

        expect($locked)->not->toBeEmpty();

        foreach ($locked as $field) {
            expect($field['reason'])->toBeString()
                ->and(str_contains((string) $field['reason'], "\n"))->toBeFalse()
                ->and(mb_strlen((string) $field['reason']))->toBeLessThanOrEqual(140);
        }
    }

    // A reader is told in one line what they may do; an editor is not told it at all.
    expect(settingsPageProps($this, Role::PRESIDENT, '/settings/profile')['canEdit'])->toBeFalse()
        ->and(settingsPageProps($this, Role::COMMUNITY_SUPER_ADMIN, '/settings/profile')['canEdit'])->toBeTrue();
});

it('points the profile at the estate\'s own logo once one is set, and at nothing before', function (): void {
    $before = EstateSetting::current()->logo_path;

    try {
        EstateSetting::current()->forceFill(['logo_path' => null])->save();

        expect(settingsPageProps($this, Role::COMMUNITY_SUPER_ADMIN, '/settings/profile')['logo']['url'])->toBeNull();

        FacilitiesFixture::boot();
        EstateSetting::current()->forceFill(['logo_path' => 'branding/logo-2026-10-12.png'])->save();

        $url = (string) settingsPageProps($this, Role::COMMUNITY_SUPER_ADMIN, '/settings/profile')['logo']['url'];

        // The served route, never the disk path, fingerprinted by the stored file so a new upload is drawn at once.
        expect($url)->toEndWith('/settings/profile/logo?v='.substr(sha1('branding/logo-2026-10-12.png'), 0, 12))
            ->and(str_contains($url, 'branding/'))->toBeFalse();
    } finally {
        FacilitiesFixture::boot();
        EstateSetting::current()->forceFill(['logo_path' => $before])->save();
    }
});

/* ---------------------------------------------------------------- 2. users and roles */

it('sends the users list with no em dash, and each account in the list\'s words, Active or Inactive', function (): void {
    foreach ([Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT] as $role) {
        $props = settingsPageProps($this, $role, '/settings/users');

        expect(settingsEmDashes($props))->toBe([])
            ->and($props['rows'])->not->toBeEmpty();

        foreach ($props['rows'] as $row) {
            expect($row['status_label'])->toBeIn(['Active', 'Inactive']);
        }
    }

    // The two refusals a row prints most often, one line each and no em dash.
    expect(Settings::LAST_SUPER_ADMIN)->not->toContain('—')
        ->and(Settings::SENIORMOST_OFFICER)->not->toContain('—');

    // A reader changes nobody: the page draws them no control at all (M96 level 3).
    $president = settingsPageProps($this, Role::PRESIDENT, '/settings/users');

    expect($president['canInvite'])->toBeFalse()
        ->and($president['canManage'])->toBeFalse();
});

it('asks before every act on a person, in the console\'s dialog, naming what will change; nothing red, nothing inline', function (): void {
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/Users.vue'));

    // The forms that opened in the table or on the page are dialogs now.
    expect(str_contains($page, 'class="inv-panel"'))->toBeFalse()
        ->and(str_contains($page, 'class="manage-panel"'))->toBeFalse()
        ->and(str_contains($page, '<form'))->toBeFalse();

    // Making somebody an administrator went at the first press: it is a dialog of its own now, and its read-back names
    // the person, the role they hold and the one they will hold.
    expect($page)->toContain('title="Make somebody an administrator"')
        ->and($page)->toContain('@confirm="makeAdministrator"')
        ->and($page)->toContain('goes from ${adminChosen.value.role_label} to ${adminRoleLabel.value}')
        // A role change says the same, and refuses to save nothing.
        ->and($page)->toContain('goes from ${now} to ${next}')
        ->and($page)->toContain(":confirm-blocked-reason=\"manageChanges ? null : 'Nothing has changed yet.'\"")
        // Withdrawing an invitation asks too.
        ->and($page)->toContain('@confirm="postWithdraw"')
        // And an invitation never starts on an administrator's role: one press of Send handed that out.
        ->and($page)->toContain('props.roles.find((role) => !role.hand_out_refusal && !role.is_administrator)');

    // Remove is the dialog's danger tone (a red outline), never the solid red button it was.
    expect($page)->toContain('tone="danger"')
        ->and(str_contains($page, 'u-danger'))->toBeFalse()
        ->and(str_contains($page, 'var(--red-'))->toBeFalse();

    // Level 3: a reader has no actions column and no Invite; the row's own refusals are said under it (level 2).
    expect($page)->toContain('<td v-if="canManage">')
        ->and($page)->toContain('<span v-if="readsOnly" class="view-badge">View only</span>')
        ->and($page)->toContain('<p v-for="reason in rowRefusals(row)" :key="reason" class="u-why">{{ reason }}</p>');
});

/* ---------------------------------------------------------------- 3. the role access matrix and its editor */

it('sends the matrix with no em dash but the lone one for no access, and its note says only what is true', function (): void {
    foreach ([Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT] as $role) {
        $props = settingsPageProps($this, $role, '/settings/roles');

        expect(settingsEmDashes($props))->toBe([]);
    }

    $props = settingsPageProps($this, Role::COMMUNITY_SUPER_ADMIN, '/settings/roles');

    // The note said the Community Super Admin "isn't shown here", over a matrix that shows its column.
    expect(collect($props['roles'])->pluck('name')->all())->toContain(Role::COMMUNITY_SUPER_ADMIN)
        ->and(str_contains($props['audit_note'], 'shown'))->toBeFalse()
        ->and($props['audit_note'])->toBe(Settings::MATRIX_AUDIT_NOTE);
});

it('sends one role\'s permissions page with no em dash', function (): void {
    $treasurer = Role::named(Role::TREASURER);
    $props = settingsPageProps($this, Role::COMMUNITY_SUPER_ADMIN, '/settings/roles/'.$treasurer->id.'/permissions');

    expect(settingsEmDashes($props))->toBe([])
        ->and($props['role']['label'])->toBe('Treasurer');
});

it('saves no permission without a review naming every single change in words, on a page of its own', function (): void {
    $page = str_replace("\r\n", "\n", (string) file_get_contents(resource_path('js/Pages/Estate/Settings/RolePermissions.vue')));
    $roles = str_replace("\r\n", "\n", (string) file_get_contents(resource_path('js/Pages/Estate/Settings/Roles.vue')));

    // A page, not a modal (Hassam, 12 Oct): no dialog holds the table, and the shell's back control returns to Roles.
    expect(preg_match('/<ConsoleDialog\b[^>]*>(?:(?!<\/ConsoleDialog>)[\s\S])*<table/', $page))->toBe(0, 'the table is inside a dialog')
        ->and($page)->toContain(":back=\"{ href: rolesHref, label: 'Back to roles' }\"")
        // Every single change, one sentence each, counted from exactly what is posted against exactly what is saved.
        ->and($page)->toContain('`${props.role.label} gains ${grantWords(key, grant)} on ${module}`')
        ->and($page)->toContain('`${props.role.label} loses ${grantWords(key, grant)} on ${module}`')
        ->and($page)->toContain('<li v-for="change in changes" :key="change.key">{{ change.text }}</li>')
        // The foot's button reviews; only the review's button posts, and nothing else on the page does.
        ->and($page)->toContain('if (!reviewing.value || changeCount.value === 0 || saveForm.processing) {')
        ->and(substr_count($page, '.post('))->toBe(1)
        // A running count of what has changed, where "Nothing has been changed yet" was.
        ->and($page)->toContain('`${changeCount.value} ${changeCount.value === 1 ? \'change\' : \'changes\'} made`')
        ->and(str_contains($page, 'Nothing has been changed yet'))->toBeFalse()
        // The header row kept in view as the table scrolls, Save and Cancel kept in view, a card a module on a phone.
        // Below the red bar AND the shell's sticky top bar (its measured height), since the top bar was pinned (batch 1).
        ->and($page)->toContain("position: sticky;\n    top: calc(var(--panic-bar-h, 0px) + var(--rp-head-top, 0px));")
        ->and($page)->toContain("position: sticky;\n    bottom: 0;")
        ->and($page)->toContain('@media (max-width: 760px)')
        // Leaving with changes unsaved asks first; nothing on the page is red.
        ->and($page)->toContain('title="Discard these permission changes?"')
        ->and(str_contains($page, 'var(--red-'))->toBeFalse();

    // The roles list keeps no editor: Edit permissions and a matrix cell lead to the page, and nothing there posts a
    // permission. Its forms are short dialogs: Add role, Rename, Delete.
    expect($roles)->toContain(':href="permissionsHref(role)"')
        ->and($roles)->toContain(':href="permissionsHref(editorRoleByName[cell.role])"')
        ->and(str_contains($roles, 'savePermissions'))->toBeFalse()
        ->and(str_contains($roles, 'rl-grid'))->toBeFalse()
        ->and(str_contains($roles, '<form'))->toBeFalse()
        ->and($roles)->toContain('confirm-label="Delete the role"')
        // Delete is the dialog's red outline, never solid red; nothing on the page is red.
        ->and(str_contains($roles, 'rl-danger'))->toBeFalse()
        ->and(str_contains($roles, 'var(--red-'))->toBeFalse()
        // A reader: no Invite and no editor, a badge; every role's refusals said under its row.
        ->and($roles)->toContain('<span v-else-if="!editor.can_edit" class="view-badge">View only</span>')
        ->and($roles)->toContain('<tr v-if="rowRefusals(role).length > 0" class="rl-why-row">');
});

/* ---------------------------------------------------------------- 4. the features and the plan */

it('shows every feature read only with the plan: a state in the list\'s words and where it comes from, no em dash', function (): void {
    foreach ([Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT] as $role) {
        $props = settingsPageProps($this, $role, '/settings/features');

        expect(settingsEmDashes($props))->toBe([]);

        $rows = collect($props['groups'])->flatMap(fn (array $group): array => $group['rows']);

        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            // The line under the state says where it comes from; a core row's said its description again (12 Oct).
            expect($row['state_label'])->toBeIn(['Active', 'Inactive'])
                ->and((string) $row['plan_line'])->not->toBe('')
                ->and((string) $row['plan_line'])->not->toBe((string) $row['description']);
        }
    }

    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/Features.vue'));

    // No switch on a plan row: the one left is the estate's own rule about invites, which asks why in its dialog.
    expect(substr_count($page, 'role="switch"'))->toBe(1)
        ->and(str_contains($page, 'seg-toggle-item'))->toBeFalse()
        ->and(str_contains($page, '/settings/features/'))->toBeFalse()
        ->and($page)->toContain('{{ row.state_label }}')
        ->and($page)->toContain('{{ row.plan_line }}')
        ->and(str_contains($page, 'var(--red-'))->toBeFalse();
});

/* ---------------------------------------------------------------- 6. the audit log */

it('draws the audit log in all five states, on its board, with no em dash sent', function (): void {
    config(['estate_features.audit_log' => true]);

    foreach ([Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT] as $role) {
        expect(settingsEmDashes(settingsPageProps($this, $role, '/settings/audit')))->toBe([]);
    }

    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/Audit.vue'));

    // A failed read or a role without Settings drew an empty log; each says what it is now.
    expect($page)->toContain('variant="error"')
        ->and($page)->toContain('variant="denied"')
        ->and($page)->toContain('variant="filtered"')
        ->and($page)->toContain('variant="first-use"')
        ->and($page)->toContain('<SkeletonRows v-if="state.isLoading.value"')
        // Opened by its address it wore no board stylesheet, so its shell drew unstyled.
        ->and($page)->toContain("useWireframe('community-admin-06-settings-estate-profile-users-and-roles')")
        // Still read only: one form, the filters, and it only reads.
        ->and(substr_count($page, '<form'))->toBe(1)
        ->and(str_contains($page, 'router.post'))->toBeFalse();
});

/* ---------------------------------------------------------------- 7. subscription and billing */

it('sends billing with no em dash but the lone one for an empty value, and draws its states and its words plainly', function (): void {
    foreach ([Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT] as $role) {
        expect(settingsEmDashes(settingsPageProps($this, $role, '/settings/billing')))->toBe([]);
    }

    $billing = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/Billing.vue'));
    $invoice = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/BillingInvoice.vue'));

    expect($billing)->toContain('variant="error"')
        ->and(str_contains($billing, '/ mo'))->toBeFalse()
        ->and($billing)->toContain('Per unit a month');

    // The invoice is a nested page: the shell's back control in words, where it was a bare arrow.
    expect($invoice)->toContain(':back="{ href: billingHref, label: \'Back to Billing & subscription\' }"')
        ->and(str_contains($invoice, '#lead'))->toBeFalse()
        // A reconciliation warning is amber, and the note says what is true, without an em dash.
        ->and(str_contains($invoice, 'var(--red-'))->toBeFalse()
        ->and(str_contains($invoice, 'subscription lines only'))->toBeFalse()
        ->and(str_contains(substr($invoice, (int) strpos($invoice, '<template>')), '—'))->toBeFalse()
        ->and($invoice)->toContain('variant="error"');
});

it('gives a reader no Save and no upload, and an editor a greyed Save with its reason beside it, in amber not red', function (): void {
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/Profile.vue'));

    // Level 3 for a reader: the badge where Save was, and the upload only inside the editor's branch.
    expect($page)->toContain('<span v-if="!canEdit" class="view-badge">View only</span>')
        ->and($page)->toContain('<template v-if="canEdit">')
        // Level 2 for an editor: the reason on the screen beside Save, not its tooltip.
        ->and($page)->toContain('<span v-if="saveBlockedBy !== null" class="save-why">{{ saveBlockedBy }}</span>')
        ->and(str_contains($page, ':title="saveBlockedBy'))->toBeFalse()
        // A refusal under its field, and nothing red on the page.
        ->and($page)->toContain('class="field-error"')
        ->and(str_contains($page, 'var(--red-'))->toBeFalse()
        ->and(str_contains($page, 'profile-refusal'))->toBeFalse();
});

/* ---------------------------------------------------------------- guard tracking (Hassam, 12 Oct, guard live tracking) */

it('shows guard tracking with what the estate chose and the platform defaults, and a reader no Save', function (): void {
    $props = settingsPageProps($this, Role::COMMUNITY_SUPER_ADMIN, '/settings/guard-tracking');

    expect(settingsEmDashes($props))->toBe([])
        ->and($props['canEdit'])->toBeTrue()
        ->and($props['defaults'])->toBe(SiteTrackingSettings::defaults())
        ->and($props['bounds'])->toBe(SiteTrackingSettings::BOUNDS)
        ->and(array_keys($props['chosen']))->toBe(SiteTrackingSettings::KEYS)
        ->and(collect($props['sections'])->firstWhere('active', true)['label'])->toBe('Guard tracking');

    expect(settingsPageProps($this, Role::PRESIDENT, '/settings/guard-tracking')['canEdit'])->toBeFalse();
});

it('saves guard tracking only for the role that configures Settings, and says a refusal in its own words', function (): void {
    $url = FacilitiesFixture::url('/settings/guard-tracking');
    $nothing = array_fill_keys(SiteTrackingSettings::KEYS, null);

    $this->actingAs(FacilitiesFixture::viewer(Role::PRESIDENT))
        ->post($url, [...$nothing, 'moving_interval_s' => 60])
        ->assertForbidden();

    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    // Faster than "about once a minute" is refused, nothing written.
    $this->actingAs($admin)->from($url)->post($url, [...$nothing, 'moving_interval_s' => 30])
        ->assertSessionHasErrors('tracking');

    expect(SiteTrackingSettings::chosen(FacilitiesFixture::ESTATE)['moving_interval_s'])->toBeNull();

    $this->actingAs($admin)->from($url)->post($url, [...$nothing, 'moving_interval_s' => 60, 'no_signal_after_s' => 600])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect(SiteTrackingSettings::chosen(FacilitiesFixture::ESTATE))->toMatchArray(['moving_interval_s' => 60, 'no_signal_after_s' => 600, 'stationary_interval_s' => null]);
});

it('tells the estate about its guards\' consent in one line, with no record, no names and no dates', function (): void {
    $props = settingsPageProps($this, Role::COMMUNITY_SUPER_ADMIN, '/settings/privacy');

    // Hassam, 12 Oct: a guard's consent is employment data between the guard and Gemini; the estate gets assurance.
    expect(settingsEmDashes($props))->toBe([])
        ->and($props['guard_consent'])->toBeIn([ConsentAssurance::ALL_AGREED, ConsentAssurance::NOBODY_ON_DUTY, ConsentAssurance::NOT_ALL_AGREED])
        ->and(array_key_exists('consent', $props))->toBeFalse();

    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/Privacy.vue'));

    expect($page)->toContain('<p class="pv-assurance">{{ guard_consent }}</p>')
        ->and(str_contains($page, 'consent.rows'))->toBeFalse();
});

it('says first that the notification preferences are saved and not yet used, and keeps them editable', function (): void {
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/Notifications.vue'));

    expect(str_contains($page, 'These preferences are saved but are not yet used. Every notification currently goes to everyone entitled to receive it.'))
        ->toBeTrue('the page claims the switches do something')
        // Still editable: a reader's switches are inert, an editor's save posts.
        ->and(substr_count($page, 'router.post('))->toBe(1)
        ->and($page)->toContain(':disabled="!canEdit"');
});

it('asks before saving what household members may do, naming every change, and saves what is drawn', function (): void {
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/ResidentAccess.vue'));

    expect($page)->toContain('<span v-if="!canEdit" class="view-badge">View only</span>')
        ->and($page)->toContain('<li v-for="change in changes" :key="change.key">{{ change.text }}</li>')
        ->and($page)->toContain('`${LEVEL_WORD[level]} ${after ? \'gains\' : \'loses\'} “${group.label}”`')
        ->and($page)->toContain(':error="refusal"')
        // What is saved is what is drawn; only the question's button posts; the red refusal band is gone.
        ->and($page)->toContain("dependent: Object.fromEntries(props.groups.map((g) => [g.key, isOn('dependent', g)])),")
        ->and(substr_count($page, 'router.post('))->toBe(1)
        ->and(str_contains($page, 'ra-refusal'))->toBeFalse()
        ->and(str_contains($page, 'var(--red-'))->toBeFalse();
});

it('asks before saving guard tracking, naming every change, and keeps a refusal in the question\'s band', function (): void {
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/GuardTracking.vue'));

    expect($page)->toContain('<span v-if="!canEdit" class="view-badge">View only</span>')
        ->and($page)->toContain('<span v-if="saveBlockedBy !== null" class="save-why">{{ saveBlockedBy }}</span>')
        ->and($page)->toContain('<li v-for="change in changes" :key="change.key">{{ change.text }}</li>')
        ->and($page)->toContain(':error="refusal"')
        // Only the question's button posts.
        ->and(substr_count($page, 'router.post('))->toBe(1)
        ->and($page)->toContain('@confirm="save"')
        // "No signal" shows its consequence and cannot be picked under its floor (Hassam, 12 Oct).
        ->and($page)->toContain('{{ consequence }}')
        ->and($page)->toContain(':disabled="below(row, value)"')
        ->and(str_contains($page, 'var(--red-'))->toBeFalse();
});
