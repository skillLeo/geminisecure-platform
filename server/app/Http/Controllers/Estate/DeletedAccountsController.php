<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Household;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\DeletedAccounts;
use App\Services\Estate\HouseholdRemoval;
use App\Services\ResidentApp\AccountDeletion;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * RESIDENTS → DELETED ACCOUNTS (M18H §6).
 *
 *   index      every account deleted and still restorable: who, which lot, when, by whom, days left, what the lot owes;
 *              a search, "expiring within 7 days", and the households the deletions left with nobody primary.
 *   restore    Residents `update` — the permission that ends and gives back app access (Q-M44). The account comes back as
 *              it was; the person is emailed; audited.
 *   permanent  THE COMMUNITY SUPER ADMIN ONLY, with the person's name typed in full and a reason (§5). Irreversible:
 *              nothing of the person is left on the account or the register entry, and every row that points at them
 *              keeps its amounts and times. Audited — who, when, why — with no name, because the audit log outlives them.
 */
class DeletedAccountsController extends Controller
{
    public function index(Request $request, DeletedAccounts $deleted, HouseholdRemoval $removal): Response
    {
        $maySeeMoney = $request->user()->can('estate.dues_ledger.view');

        $board = $deleted->board(
            (string) tenant()->getTenantKey(),
            $request->string('q')->toString(),
            $request->boolean('expiring'),
            $maySeeMoney,
            // A page at a time, in the order its headings ask for (M32 F5).
            table: DeletedAccounts::table($request, $maySeeMoney),
        );

        return inertia('Estate/Residents/DeletedAccounts', [
            'estate' => ['name' => (string) tenant()->name],
            ...$board,
            'canRestore' => $request->user()->can('estate.residents.update'),
            'restoreBlockedReason' => 'Restoring an account gives somebody back their sign-in and their e-pass, so it needs Residents update access. You are able to read this list.',
            'canDeletePermanently' => self::isCommunitySuperAdmin($request->user()),
            'permanentBlockedReason' => 'Deleting an account permanently cannot be undone, so only the Community Super Admin can do it. Otherwise it happens by itself when the window closes.',
            // M21 §3.1: households removed from their lot and still restorable.
            'removedHouseholds' => $removal->board(),
        ]);
    }

    /**
     * A removed household back on its lot, with the people and accounts its removal took (M21 §3.1).
     *
     * A REFUSAL IS SAID IN THE RESTORE DIALOG (Hassam, 12 Oct): an error the dialog's band shows, the dialog staying open.
     * It was a flash, which Inertia counts as done: the dialog closed and the refusal was toasted behind it. One put back
     * by somebody else a moment ago is refused in words ("This household is on its lot…"), never a page not found.
     */
    public function restoreHousehold(Request $request, int $household, HouseholdRemoval $removal): RedirectResponse
    {
        $row = Household::withRemoved()->whereKey($household)->first();
        abort_unless($row instanceof Household, 404);

        try {
            $accounts = $removal->restore($row, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['restore' => $refused->getMessage()]);
        }

        return back()->with('success', $row->name.' is back on its lot. '.($accounts === 1 ? '1 app account was' : $accounts.' app accounts were').' restored, and each person has been emailed. Passes and bookings the removal cancelled stay cancelled.');
    }

    /** The same rule as a household's: refused in the dialog's band; one restored a moment ago is refused in words. */
    public function restore(Request $request, int $account, AccountDeletion $deletion): RedirectResponse
    {
        $row = $this->accountOrFail($account);

        try {
            $deletion->restore($row, AccountDeletion::BY_OFFICE, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['restore' => $refused->getMessage()]);
        }

        return back()->with('success', 'The account is restored. They can sign in again with the same email address, and have been emailed to say so.');
    }

    public function destroyPermanently(Request $request, int $account, AccountDeletion $deletion, DeletedAccounts $deleted): RedirectResponse
    {
        abort_unless(self::isCommunitySuperAdmin($request->user()), 403, 'Only the Community Super Admin can delete an account permanently.');

        $row = $this->deletedOrFail($account);
        $name = collect($deleted->board((string) tenant()->getTenantKey())['rows'])->firstWhere('id', $row->id)['name'] ?? '';

        $data = $request->validate([
            'confirm_name' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'min:10', 'max:300'],
        ], [
            'confirm_name.required' => 'Type the person\'s name to confirm.',
            'reason.required' => 'Say why it must go now rather than when the window closes.',
            'reason.min' => 'Say why in a sentence; it is kept in the audit trail.',
        ]);

        if (mb_strtolower(trim($data['confirm_name'])) !== mb_strtolower(trim((string) $name))) {
            throw ValidationException::withMessages(['confirm_name' => 'That is not the name on this account. Type it exactly as shown: '.$name.'.']);
        }

        $reference = $deletion->anonymise($row, AccountDeletion::PERMANENT, $request->user(), $data['reason']);

        return back()->with('success', 'Deleted permanently. Nothing of the person is left on the account; its reference is '.$reference.'.');
    }

    private function deletedOrFail(int $id): ResidentAccount
    {
        $account = ResidentAccount::query()->where('tenant_id', (string) tenant()->getTenantKey())->find($id);

        abort_unless($account instanceof ResidentAccount && $account->isDeleted(), 404);

        return $account;
    }

    /** An account of this estate with something of it left, deleted or not: nothing left of it is a page not found. */
    private function accountOrFail(int $id): ResidentAccount
    {
        $account = ResidentAccount::query()->where('tenant_id', (string) tenant()->getTenantKey())->find($id);

        abort_unless($account instanceof ResidentAccount && $account->anonymised_at === null, 404);

        return $account;
    }

    /** The Community Super Admin of this estate, or this estate's own version of that role (M11 B4). */
    private static function isCommunitySuperAdmin(User $user): bool
    {
        $ids = Role::idsStandingFor(Role::COMMUNITY_SUPER_ADMIN, (string) tenant()->getTenantKey());

        return $ids !== [] && $user->roles()->whereIn('roles.id', $ids)->exists();
    }
}
