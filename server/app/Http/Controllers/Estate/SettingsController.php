<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Api\AccessLevels;
use App\Api\AccessRules;
use App\Enums\Console;
use App\Http\Controllers\Controller;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Services\Estate\EstateBranding;
use App\Services\Estate\ResidentInviteTrust;
use App\Services\Estate\Settings;
use App\Services\Exports\Exporter;
use App\Services\Gemini\BillingOverview;
use App\Services\Gemini\InvoiceActions;
use App\Services\InvitationNotSent;
use App\Services\Invitations;
use App\Services\Rbac\RoleEditor;
use App\Services\Tracking\ConsentAssurance;
use App\Services\Tracking\SiteTrackingSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Estate profile, users, feature toggles and the role access matrix — board
 * screens 21, 22, 23 and 24.
 *
 * READING SETTINGS IS `view`; CHANGING ONE IS NOT. The seeded estate matrix
 * gives the Settings module Full to the Community Super Admin, View to the
 * President and Vice President, and nothing at all to the Secretary, Property
 * Manager, Treasurer, Admin Assistant or the estate's Head of Security. So a
 * President opens every settings screen and can save none of them, which is
 * exactly what "View — read-only" means and is asserted both ways in
 * `EstateSettingsTest`.
 *
 * FIVE ACTIONS HERE CHANGE WHAT A ROLE MAY DO, AND NOT ONE OF THEM DECIDES
 * ANYTHING (M10 §4). Until M10 there was no such action, deliberately: changing
 * a role is an `approve`-level act on Settings (D-013) and no estate role held
 * `estate.settings.approve`. The client has asked for the Community Super Admin
 * to create, copy, rename, re-grant and delete roles, the seeded matrix now
 * gives that one cell the Approver tag, and these five actions are the door.
 * Each validates the SHAPE of the request and nothing else, then hands it to
 * `App\Services\Rbac\RoleEditor`, which holds every guard rail both consoles
 * share — nobody grants or takes away what they do not hold, nothing names a
 * module outside this console, the eight shared roles are Gemini's, Ruling 1
 * binds a copy of the Property Manager, and every write is audited and takes
 * effect on the next request. A rule restated here would be a second copy free
 * to disagree with the first.
 *
 * A ROLE ID FROM A URL IS RESOLVED BY `RoleEditor::find()`, NEVER BY ROUTE-MODEL
 * BINDING. Binding would answer 404 for a role that does not exist and resolve
 * another estate's, which is exactly the difference a guessed id must not
 * reveal; `find()` refuses both in one sentence. Every refusal comes back as
 * `withErrors(['role' => <the sentence>])`, the shape the rest of this class
 * already uses, so the screen prints what the server said.
 *
 * The route file carries the gates — `can:estate.settings.approve` on the five
 * role writes, `manage_people` on managing or removing a person, `edit_profile`
 * on the profile, logo and notification defaults (the two halves of what
 * `update` was until M11 B3), `create` on inviting. A controller that re-checked
 * them would be a second place to keep in step with the matrix.
 */
class SettingsController extends Controller
{
    /**
     * The one row on board 22 that cannot be managed, and it is not a permission.
     *
     * The seniormost officer demoting themselves, or being suspended, leaves an
     * estate with nobody who could undo it. Every access system needs this rule
     * and the screen says it on the row rather than failing on the press. The
     * words are the service's, so the row and the refusal cannot drift apart.
     */
    private const OWNER_NOT_MANAGEABLE = Settings::SENIORMOST_OFFICER;

    /** Estate profile — board community-admin-21. */
    public function profile(Request $request, Settings $settings): Response
    {
        return inertia('Estate/Settings/Profile', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('profile', (string) tenant()->getTenantKey()),
            ...$settings->profileBoard(),

            // Its own permission since M11 B3 (D-150): not the one that moves people between roles.
            'canEdit' => $request->user()->can('estate.settings.edit_profile'),
            'blockedReason' => 'You can read the estate profile. Changing it needs the Edit the estate profile permission on Settings.',
            'logoRules' => EstateBranding::RULES,
        ]);
    }

    /**
     * Upload the estate's logo — board 21 (12 §1, and the PDFs that need it).
     *
     * THE OLD ONE IS KEPT, which is the third thing the old inert reason asked
     * for. A logo is printed on paperwork households already hold, and the swap
     * is recorded in the audit log with both paths — so a receipt issued last
     * March can be explained by what the estate's mark was in March. Nothing
     * deletes a file that a document may have been rendered from.
     */
    public function uploadLogo(Request $request, EstateBranding $branding): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'image', 'mimes:'.implode(',', EstateBranding::EXTENSIONS), 'max:'.EstateBranding::MAX_KB],
        ]);

        try {
            $branding->store($request->file('logo'), $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['logo' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/settings/profile'))
            ->with('success', 'Logo updated. It appears on every statement, receipt and certificate issued from now on; documents already issued keep the mark they were printed with.');
    }

    /**
     * The logo itself.
     *
     * SERVED, NOT PUBLIC. The file lives on the private disk under this
     * estate's own prefix, and this route is inside the estate's auth group —
     * so one estate's mark cannot be fetched from another's URL, which is the
     * rule the column's own migration comment states.
     */
    public function logo(EstateBranding $branding): StreamedResponse
    {
        return $branding->stream();
    }

    /** Users & roles — board community-admin-22. */
    public function users(Request $request, Settings $settings): Response
    {
        $viewer = $request->user();

        return inertia('Estate/Settings/Users', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('users', (string) tenant()->getTenantKey()),

            /*
             * Given the viewer, so each row carries the sentence that would
             * refuse changing or removing that person — the last Community
             * Super Admin among them — before anybody presses anything.
             */
            ...$settings->usersBoard((string) tenant()->getTenantKey(), $viewer),

            /*
             * The roles a committee may invite somebody into or move somebody
             * to — the eight every community shares and this estate's own,
             * never Gemini's and never another estate's — each saying whether
             * this viewer may hand it out. And the invitations still open, with
             * who sent each and when it lapses. Never the token.
             */
            'roles' => $settings->invitableRoles($viewer),
            'invitations' => $settings->pendingInvitations((string) tenant()->getTenantKey()),
            'canInvite' => $viewer->can('estate.settings.create'),
            'blockedReason' => 'Inviting somebody issues a sign-in, so it needs Settings create access.',
            // Its own permission since M11 B3 (D-150): not the one that edits the profile.
            'canManage' => $viewer->can('estate.settings.manage_people'),
            'manageBlockedReason' => 'You can read this list. Changing somebody\'s role or access needs the Manage people permission on Settings.',
            'ownerReason' => self::OWNER_NOT_MANAGEABLE,

            /*
             * ADMINISTRATORS ARE MADE AND UNMADE BY ADMINISTRATORS (M10 §4). An
             * administrator is whoever holds a role that can change roles; the
             * seeded one is the Community Super Admin. Adding one is giving
             * somebody that role and removing one is giving them another, both
             * through `manageUser()`, which asks `RoleEditor` whether this
             * viewer may — so `canAdminister` only decides whether the screen
             * draws the controls live, and the server decides regardless.
             */
            'canAdminister' => $viewer->can(RoleEditor::editPermission(Console::Estate)),
            // The machine name the screen keys on: this community's own version of the Community Super
            // Admin where it has made one (M11 B4), the shared role otherwise.
            'administratorRole' => (string) (Role::forEstate(Role::COMMUNITY_SUPER_ADMIN, (string) tenant()?->getTenantKey())->name ?? Role::COMMUNITY_SUPER_ADMIN),
            'administratorNote' => self::ADMINISTRATOR_NOTE,
        ]);
    }

    /**
     * What an administrator is, in the words board 22 prints above the list of
     * them.
     */
    private const ADMINISTRATOR_NOTE = 'Administrators can change what every role on this estate may do, and are the '.
        'only people who can make somebody else an administrator or stop them being one. Making somebody an '.
        'administrator gives them the Community Super Admin role; to stop, give them another role.';

    /**
     * Change what somebody may do here, or withdraw it — board 22's "Manage".
     *
     * Both acts are on one panel and both are recorded: a role change changes
     * what they may do, a suspension stops them doing anything, and both are
     * decisions about a person's standing in a community.
     */
    public function manageUser(Request $request, int $assignment, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', 'max:64'],
            'status' => ['required', 'string', 'in:active,suspended'],
        ]);

        $record = EstateAssignment::query()->with(['user', 'role'])->find($assignment);

        // The sentence the service gives another estate's row and Gemini's, so
        // an id nobody holds answers exactly as one somebody else does.
        if ($record === null) {
            return back()->withErrors(['role' => Settings::NOT_ON_COMMITTEE]);
        }

        try {
            $settings->manageUser($record, $data['role'], $data['status'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['role' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/settings/users'))
            ->with('success', $record->user->name.' updated. The change is on the estate\'s audit log with who made it and when.');
    }

    /**
     * Take somebody off the committee — board 22's "Remove" (M10 §4).
     *
     * REMOVED, NOT DELETED: `Settings::removeUser()` deactivates the assignment,
     * suspends the account and leaves it holding no role, and keeps both rows,
     * because who sat on a committee and until when is what an audit reads. The
     * last Community Super Admin, the seniormost officer, an administrator
     * removed by somebody who is not one, and the viewer themselves are each
     * refused there, in the words the row already printed.
     *
     * The id is resolved by hand, as `manageUser()` resolves it, and another
     * estate's assignment is refused in the same sentence as one that does not
     * exist.
     */
    public function removeUser(Request $request, int $assignment, Settings $settings): RedirectResponse
    {
        $record = EstateAssignment::query()->with(['user', 'role'])->find($assignment);

        if ($record === null) {
            return back()->withErrors(['remove' => Settings::NOT_ON_COMMITTEE]);
        }

        try {
            $settings->removeUser($record, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['remove' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/settings/users'))
            ->with('success', sprintf(
                '%s is off the committee. Their account is suspended and holds no role, so it reaches nothing; the '.
                'record of what they held, and until when, stays on the audit log. They are listed under Taken off '.
                'the committee, where they can be reinstated.',
                $record->user->name,
            ));
    }

    /**
     * Bring somebody back onto the committee — board 22's "Reinstate" (M10 §4).
     *
     * `Settings::reinstateUser()` undoes exactly what a removal did: the
     * assignment and the account are active again and they hold the role they
     * were removed from. Behind `manage_people` (Settings `update`'s half that
     * seats people, M11 B3), the same gate as removing them — one act and its
     * inverse. Resolved by hand like the two above, and a row
     * that is not a removed member of this committee is refused in one sentence
     * whether it exists or not.
     */
    public function reinstateUser(Request $request, int $assignment, Settings $settings): RedirectResponse
    {
        $record = EstateAssignment::query()->with(['user', 'role'])->find($assignment);

        if ($record === null) {
            return back()->withErrors(['reinstate' => Settings::NOT_REMOVED_HERE]);
        }

        try {
            $reinstated = $settings->reinstateUser($record, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reinstate' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/settings/users'))
            ->with('success', sprintf(
                '%s is back on the committee as %s, and can sign in again. The change is on the estate\'s audit log '.
                'with who made it and when.',
                $reinstated->user->name,
                (string) ($reinstated->role->label ?? $reinstated->role->name),
            ));
    }

    /**
     * Invite somebody onto the committee — board 22's "Invite user" (12 §2, Wave 1).
     *
     * THE ROLE IS RESOLVED THROUGH `RoleEditor::assignableNamed()` (M10 §4): the
     * eight shared roles and this estate's own, by machine name, and anything
     * else — a Gemini role, another estate's, a name nobody made — refused in
     * one sentence that discloses nothing. `Invitations::invite()` fences the
     * tenant again, because a service is called from more than one door.
     */
    public function invite(Request $request, Invitations $invitations, RoleEditor $editor): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', 'string', 'max:190'],
        ]);

        try {
            $role = $editor->assignableNamed($request->user(), $data['role']);
        } catch (DomainException $refused) {
            return back()->withErrors(['role' => $refused->getMessage()])->withInput();
        }

        /*
         * WHAT HAPPENED, IN THE INVITATION'S ROW (DESIGN §11 row 1). This said "Invitation sent" whatever the
         * relay did, and a refusal was a server error page. The invitation is saved either way; the answer says
         * whether the email went, and the screen draws it beside the invitation it is about.
         */
        try {
            $invitation = $invitations->invite($data['email'], $role, (string) tenant()->getTenantKey(), $request->user());
        } catch (InvitationNotSent $unsent) {
            return redirect()
                ->to($this->path('/settings/users'))
                ->with('invitation_outcome', ['id' => $unsent->invitation->id, 'state' => $unsent->state(), 'message' => $unsent->getMessage()]);
        } catch (DomainException $refused) {
            return back()->withErrors(['email' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/settings/users'))
            ->with('invitation_outcome', ['id' => $invitation->id, 'state' => 'sent', 'message' => sprintf(
                // Said the moment it went: "just now" belongs to no clock (M19A §4.1); a date always has its weekday (§4.4).
                'Invitation emailed to %s as %s just now. It stands until %s.',
                $invitation->email,
                (string) ($role->label ?? $role->name),
                $invitation->expires_at->format('l, F j'),
            )]);
    }

    /**
     * Send it again (DESIGN §11 row 1). The new link replaces the old one ONLY when the email goes; a refusal
     * leaves the link they hold exactly as it was, and says so in the invitation's row. Not twice in a minute.
     */
    public function resendInvitation(Request $request, Invitation $invitation, Invitations $invitations): RedirectResponse
    {
        $this->assertOwnInvitation($invitation);

        try {
            $invitations->resend($invitation, $request->user());
        } catch (InvitationNotSent $unsent) {
            return redirect()
                ->to($this->path('/settings/users'))
                ->with('invitation_outcome', ['id' => $invitation->id, 'state' => $unsent->state(), 'message' => $unsent->getMessage()]);
        } catch (DomainException $refused) {
            return back()
                ->withErrors(['invitation' => $refused->getMessage()])
                ->with('invitation_outcome', ['id' => $invitation->id, 'state' => 'refused', 'message' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/settings/users'))
            ->with('invitation_outcome', ['id' => $invitation->id, 'state' => 'sent', 'message' => sprintf(
                'Invitation emailed again to %s just now. The earlier link no longer works; this one stands until %s.',
                $invitation->email,
                $invitation->expires_at->format('l, F j'),
            )]);
    }

    public function revokeInvitation(Request $request, Invitation $invitation, Invitations $invitations): RedirectResponse
    {
        $this->assertOwnInvitation($invitation);

        try {
            $invitations->revoke($invitation, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['invitation' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/settings/users'))
            ->with('success', 'Invitation to '.$invitation->email.' withdrawn.');
    }

    /**
     * An invitation is this estate's or it is nobody's business here.
     *
     * Invitations are central rows keyed by estate; a committee acting on one
     * sent for another community is refused as not found rather than as
     * forbidden, because the row's existence is not theirs to learn either.
     */
    private function assertOwnInvitation(Invitation $invitation): void
    {
        if ($invitation->tenant_id !== (string) tenant()->getTenantKey()) {
            abort(404);
        }
    }

    /** Feature toggles — board community-admin-23. */
    public function features(Request $request, Settings $settings): Response
    {
        return inertia('Estate/Settings/Features', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('features', (string) tenant()->getTenantKey()),
            ...$settings->featuresBoard((string) tenant()->getTenantKey()),

            /*
             * `configure` and not `update`. Switching a module off changes what
             * several hundred households can do tomorrow, which is the verb
             * `AccessLevel::Full` grants and `Entry` deliberately does not —
             * "data entry, no approval" is not the same permission as changing
             * the shape of the product an estate is running.
             */
            'canToggle' => $request->user()->can('estate.settings.configure'),
            'blockedReason' => 'Changing a feature needs Settings configure access. You are able to read this screen.',

            // Household invites, stage 2: an estate rule, not a catalogue feature (DESIGN §7.4).
            'residentInvites' => [
                ...app(ResidentInviteTrust::class)->shape(),
                'canChange' => $request->user()->can('estate.settings.configure') && $request->user()->can('estate.residents.approve'),
                'blockedReason' => $request->user()->can('estate.settings.configure')
                    ? 'Trusting the main resident’s invites hands them the approval of a unit claim, so it needs Residents approve access as well as Settings configure.'
                    : 'Changing a feature needs Settings configure access. You are able to read this screen.',
            ],
        ]);
    }

    /**
     * Role access matrix — board community-admin-24, and since M10 §4 the
     * editor for this estate's own roles.
     *
     * TWO PAYLOADS, EACH FROM THE SERVICE THAT OWNS IT. The matrix itself —
     * rows, legend, notes — is `Settings::matrixBoard()`, exactly as before.
     * The editor is `RoleEditor::board()`: every role with its cells, the verbs
     * this viewer holds and may therefore offer, and for each role whether it
     * may be renamed, re-granted or deleted, with the sentence that refuses it
     * when not. The screen draws a control live only where the board says the
     * server will accept it, and prints the board's sentence where it will not.
     *
     * WHAT A COPY WOULD LEAVE OUT, SAID BEFORE THE COPY. For a viewer who can
     * edit, each role carries `RoleEditor::copyableGrid()`'s withheld list — the
     * permissions the viewer does not hold and so cannot put in a copy — so the
     * confirmation names them before anything is made rather than after.
     */
    public function roles(Request $request, Settings $settings, RoleEditor $editor): Response
    {
        $viewer = $request->user();
        $board = $editor->board($viewer);

        /*
         * Every role's withheld list at once (M11 A2) — the same list `copyableGrid()`
         * gives for each, where asking it of each role in turn was a context check, a
         * lookup and fourteen cell reads a role.
         */
        $copyOffers = $board['can_edit'] ? $editor->copyWithheld($viewer) : [];

        return inertia('Estate/Settings/Roles', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('roles', (string) tenant()->getTenantKey()),
            ...$settings->matrixBoard($viewer),
            'canInvite' => $viewer->can('estate.settings.create'),

            // Board 24's "Invite user" leads to board 22 with the panel open —
            // one form, on the screen that lists who was invited.
            'inviteHref' => $this->path('/settings/users?invite=1'),
            'inviteReason' => 'Inviting somebody issues a sign-in, so it needs Settings create access.',

            'editor' => [
                ...$board,
                'copy_withheld' => $copyOffers,
                'label_max' => RoleEditor::LABEL_MAX,
                'position_rule' => Settings::POSITION_RULE,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* board 24's editor — every decision is RoleEditor's (M10 §4) */
    /* ------------------------------------------------------------------ */

    /**
     * One role's permissions, module by module, ON A PAGE OF ITS OWN (Hassam, 12 Oct 2026). It opened in a modal: a long
     * table with a level and boxes per module and a column saying why a control is off, inside its own scrollbar, where
     * that column wrapped to a word a line. "A modal is for one short form, a confirmation or a quick choice"; a table the
     * person reads and compares across gets a page. Reached from Edit permissions on the roles list, behind the same
     * gate as the save.
     *
     * NOTHING HERE DECIDES A PERMISSION. The page draws what `RoleEditor::board()` says the server would accept for this
     * one role and posts to `setRolePermissions()`, which hands it back to `RoleEditor`; its review names every change
     * in words before anything is saved. A role this viewer may not change is refused with its own sentence, on the
     * roles list, rather than drawn as a table of dead controls.
     */
    public function rolePermissions(Request $request, RoleEditor $editor, int $role): Response|RedirectResponse
    {
        $viewer = $request->user();
        $board = $editor->board($viewer);
        $row = collect($board['roles'])->firstWhere('id', $role);

        abort_if($row === null, 404);

        if ($row['can_edit_permissions'] !== true) {
            return redirect()->to($this->path('/settings/roles'))->with('error', (string) $row['permissions_refusal']);
        }

        unset($board['roles']);

        return inertia('Estate/Settings/RolePermissions', [
            'estate' => ['name' => (string) tenant()->name],
            'role' => $row,
            'holderNames' => Settings::roleHolders((string) tenant()->getTenantKey(), $role),
            'editor' => [
                ...$board,
                'position_rule' => Settings::POSITION_RULE,
            ],
            'invariant_note' => Settings::INVARIANT_NOTE,
            'rolesHref' => $this->path('/settings/roles'),
            'saveHref' => $this->path('/settings/roles/'.$role.'/permissions'),
        ]);
    }

    /**
     * A new role of this estate's own, every module at no access unless the
     * request names it — then opened on its permissions page, because a role
     * nobody has given anything is only half made.
     */
    public function createRole(Request $request, RoleEditor $editor): RedirectResponse
    {
        $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            ...self::GRID_SHAPE,
        ]);

        try {
            $role = $editor->create($request->user(), (string) $request->input('label', ''), (array) $request->input('grid', []));
        } catch (DomainException $refused) {
            return back()->withErrors(['role' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/settings/roles/'.$role->id.'/permissions'))
            ->with('success', sprintf(
                '%s made. It belongs to this community alone and reaches nothing until it is given modules on this '.
                'page; what it is given applies from the next page anybody holding it opens.',
                (string) $role->label,
            ));
    }

    /**
     * A new role of this estate's own, starting from another role's grid — the
     * way a community gets "our own Treasurer" when the shared one is Gemini's.
     *
     * WHAT WAS LEFT OUT IS SAID. `copyableGrid()` is asked first, for the same
     * actor and the same source `copy()` then uses, so the flash names exactly
     * the permissions the copy was made without.
     */
    public function copyRole(Request $request, int $role, RoleEditor $editor): RedirectResponse
    {
        $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $viewer = $request->user();
        $label = trim((string) $request->input('label', ''));

        try {
            $source = $editor->find($viewer, $role);
            $withheld = $editor->copyableGrid($viewer, $source)['withheld'];
            $copy = $editor->copy($viewer, $source, $label === '' ? null : $label);
        } catch (DomainException $refused) {
            return back()->withErrors(['role' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/settings/roles/'.$copy->id.'/permissions'))
            ->with('success', sprintf(
                '%s made from %s. %s',
                (string) $copy->label,
                (string) ($source->label ?? $source->name),
                $withheld === []
                    ? 'Nothing was left out: it holds everything the original does.'
                    : 'Left out, because your own role does not hold them: '.implode(', ', $withheld).'.',
            ));
    }

    /** Rename one of this estate's own roles. `label` moves; the machine name never does. */
    public function renameRole(Request $request, int $role, RoleEditor $editor): RedirectResponse
    {
        $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $renamed = $editor->rename($request->user(), $editor->find($request->user(), $role), (string) $request->input('label', ''));
        } catch (DomainException $refused) {
            return back()->withErrors(['role' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/settings/roles'))
            ->with('success', sprintf(
                'Renamed to %s. Every list of users now prints the new name; nothing that relies on the role moves.',
                (string) $renamed->label,
            ));
    }

    /**
     * Change what one of this estate's own roles may do — one role per submit,
     * so the audit entry is one change to one role. Only the modules the request
     * names change.
     */
    public function setRolePermissions(Request $request, int $role, RoleEditor $editor): RedirectResponse
    {
        $request->validate([
            ...self::GRID_SHAPE,
            'grid' => ['required', 'array', 'min:1', 'max:64'],
        ]);

        try {
            $saved = $editor->setPermissions($request->user(), $editor->find($request->user(), $role), (array) $request->input('grid'));
        } catch (DomainException $refused) {
            return back()->withErrors(['role' => $refused->getMessage()]);
        }

        // Back to the roles list, where the matrix shows what was saved (the page it came from is a step, not a home).
        return redirect()
            ->to($this->path('/settings/roles'))
            ->with('success', sprintf(
                '%s saved. It applies from the next page anybody holding it opens, and the change is on the '.
                'estate\'s audit log with who made it.',
                (string) ($saved->label ?? $saved->name),
            ));
    }

    /** Delete one of this estate's own roles, once nobody holds it and no record names it. */
    public function deleteRole(Request $request, int $role, RoleEditor $editor): RedirectResponse
    {
        try {
            $target = $editor->find($request->user(), $role);
            $label = (string) ($target->label ?? $target->name);
            $editor->delete($request->user(), $target);
        } catch (DomainException $refused) {
            return back()->withErrors(['role' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/settings/roles'))
            ->with('success', $label.' deleted. The audit log keeps what it could do and who deleted it.');
    }

    /**
     * "Reset to GeminiSecure default" (M11 B4): this community's own version of a shared role goes,
     * and everybody here who held it holds the shared role again, exactly as GeminiSecure ships it.
     */
    public function resetRole(Request $request, int $role, RoleEditor $editor): RedirectResponse
    {
        try {
            $target = $editor->find($request->user(), $role);
            $label = (string) ($target->label ?? $target->name);
            $editor->resetToDefault($request->user(), $target);
        } catch (DomainException $refused) {
            return back()->withErrors(['role' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/settings/roles'))
            ->with('success', $label.' is back to GeminiSecure\'s default. Everybody here who held it holds the shared role again; the audit log keeps what this community\'s version could do.');
    }

    /**
     * The SHAPE of a posted grid, and only its shape.
     *
     * Enough that a value arrives as the type `RoleEditor` reads — an array per
     * cell, a string per verb — and nothing that decides whether a module, a
     * level or a verb is allowed: those are refusals with sentences, and they
     * are `RoleEditor`'s. A `level` of "owner" or a module key of
     * "gemini.payroll_accounting" gets through here and is refused there, in the
     * words the screen prints.
     */
    private const GRID_SHAPE = [
        'grid' => ['sometimes', 'array', 'max:64'],
        'grid.*' => ['array'],
        'grid.*.level' => ['nullable', 'string', 'max:32'],
        'grid.*.verbs' => ['nullable', 'array', 'max:16'],
        'grid.*.verbs.*' => ['string', 'max:32'],
        'grid.*.can_approve' => ['nullable', 'boolean'],

        // Named acts (M11 B3). Which exist on which module is RoleEditor's to say.
        'grid.*.acts' => ['nullable', 'array', 'max:16'],
        'grid.*.acts.*' => ['string', 'max:40'],
    ];

    /** Notification defaults — board community-admin-30. */
    public function notifications(Request $request, Settings $settings): Response
    {
        return inertia('Estate/Settings/Notifications', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('notifications', (string) tenant()->getTenantKey()),
            ...$settings->notificationsBoard(),

            // The profile's permission (M11 B3, D-150): a contact preference, as the save below says.
            'canEdit' => $request->user()->can('estate.settings.edit_profile'),
            'blockedReason' => 'Changing a notification default needs the Edit the estate profile permission on Settings. You are able to read this screen.',
        ]);
    }

    /**
     * Resident app access (the user, 8 Oct 2026; no board draws it): what a Dependent and a Helper may each do in the
     * Resident App, a Helper's default days and hours, and who is told when one comes through the gate — the estate's
     * own rules in one place, changed here without a release. What nobody can change is shown, and why. See
     * App\Api\AccessRules for where every rule is applied.
     */
    public function residentAccess(Request $request, Settings $settings): Response
    {
        return inertia('Estate/Settings/ResidentAccess', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('resident_access', (string) tenant()->getTenantKey()),
            ...AccessRules::board(),
            // Dependent and Helper cannot be given to anybody until the platform switches them on (the owner's approval).
            'levelsOpen' => AccessLevels::open(),
            'levelsClosedReason' => AccessLevels::NOT_OPEN,
            'canEdit' => $request->user()->can('estate.settings.configure'),
            'blockedReason' => 'Changing what Dependents and Helpers may do needs Settings configure access.',
        ]);
    }

    public function saveResidentAccess(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dependent' => ['present', 'array'],
            'dependent.*' => ['boolean'],
            'helper' => ['present', 'array'],
            'helper.*' => ['boolean'],
            'helper_days' => ['required', 'array', 'min:1'],
            'helper_days.*' => ['integer', 'between:1,7'],
            'helper_from' => ['required', 'string', 'max:5'],
            'helper_to' => ['required', 'string', 'max:5'],
            'entry' => ['required', 'array'],
            'entry.*' => ['string'],
        ]);

        try {
            AccessRules::save([
                ...$data,
                'dependent' => array_map(static fn (mixed $v): bool => (bool) $v, $data['dependent']),
                'helper' => array_map(static fn (mixed $v): bool => (bool) $v, $data['helper']),
            ], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['rules' => $refused->getMessage()]);
        }

        return back()->with('success', 'Saved. The Resident App follows these rules from the next thing each person does in it.');
    }

    /**
     * Guard tracking (Hassam, 12 Oct 2026; no board draws it): how often this estate's on-duty officers' phones send
     * their position while moving, while standing still and below the low battery line, and how long an officer may go
     * without one before the control room is told "No signal". What the estate chose, the platform's default for the
     * rest, and the bounds that keep the officers' consent words true: all `SiteTrackingSettings`'.
     */
    public function guardTracking(Request $request, Settings $settings): Response
    {
        $tenant = (string) tenant()->getTenantKey();

        return inertia('Estate/Settings/GuardTracking', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('guard_tracking', $tenant),
            'chosen' => SiteTrackingSettings::chosen($tenant),
            'defaults' => SiteTrackingSettings::defaults(),
            'bounds' => SiteTrackingSettings::BOUNDS,
            // "No signal" never sooner than the slowest reading plus this (Hassam, 12 Oct): the page shows the consequence.
            'noSignalMargin' => SiteTrackingSettings::NO_SIGNAL_MARGIN_S,
            'canEdit' => $request->user()->can('estate.settings.configure'),
            'blockedReason' => 'Changing how officers’ phones send their position needs Settings configure access. You are able to read this screen.',
        ]);
    }

    public function saveGuardTracking(Request $request): RedirectResponse
    {
        $data = $request->validate(array_fill_keys(SiteTrackingSettings::KEYS, ['present', 'nullable', 'integer']));

        try {
            SiteTrackingSettings::save(
                (string) tenant()->getTenantKey(),
                array_map(static fn (mixed $value): ?int => $value === null ? null : (int) $value, $data),
                $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['tracking' => $refused->getMessage()]);
        }

        return back()->with('success', 'Saved. Each officer’s phone sends this way from the next time it asks the server, and the control room says “No signal” after the time chosen.');
    }

    /**
     * Data & privacy — board community-admin-33. Every field is read-only; see Settings::privacyBoard(). And one line of
     * assurance about the guards on duty here (Hassam, 12 Oct 2026): whether they have agreed to position recording,
     * with no names and no dates. A guard's consent is employment data between the guard and Gemini, so the estate sees
     * no record; the record is the Gemini console's (ConsentAssurance).
     */
    public function privacy(Settings $settings): Response
    {
        $tenant = (string) tenant()->getTenantKey();

        return inertia('Estate/Settings/Privacy', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('privacy', $tenant),
            ...$settings->privacyBoard(),
            'guard_consent' => ConsentAssurance::lineFor($tenant),
        ]);
    }

    /**
     * Billing & subscription — board community-admin-40.
     *
     * Entirely a read of the CENTRAL subscription record. See
     * `Settings::billingBoard()` for the whole argument; nothing here decides
     * anything the service has not already decided.
     */
    public function billing(Request $request, Settings $settings): Response
    {
        return inertia('Estate/Settings/Billing', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('billing', (string) tenant()->getTenantKey()),
            // Every invoice, a page at a time, in the order its headings ask for (M32 F5).
            ...$settings->billingBoard((string) tenant()->getTenantKey(), Settings::billingTable($request)),
        ]);
    }

    /**
     * One of this estate's invoices, itemised — board 40's "View" (12 §2,
     * Wave 4). No board draws it.
     *
     * 404 FOR ANOTHER ESTATE'S INVOICE, the same as for one that does not
     * exist. `Settings::billingInvoice()` reads the invoice only where it
     * belongs to this estate, so there is no second answer to leak.
     */
    public function billingInvoice(Settings $settings, BillingOverview $overview, InvoiceActions $actions, int $invoice): Response
    {
        $detail = $settings->billingInvoice((string) tenant()->getTenantKey(), $invoice, $overview, $actions);

        abort_if($detail === null, 404);

        /*
         * BOTH LINKS FROM THE ESTATE'S ROOT (bug hunt B055), as every other
         * redirect and link in this class is. Written as root paths, the PDF
         * and the way back were right on an estate's own hostname and a 404
         * in the local path shape, where the estate lives at /estate/{tenant}.
         */
        return inertia('Estate/Settings/BillingInvoice', [
            'estate' => ['name' => (string) tenant()->name],
            'invoice' => $detail,
            'pdfHref' => $this->path('/settings/billing/invoices/'.$invoice.'/pdf'),
            'billingHref' => $this->path('/settings/billing'),
        ]);
    }

    /**
     * The same invoice as a PDF — the document Gemini emails, rendered from the
     * row on demand (an issued invoice never changes; see
     * `Gemini\BillingController::invoicePdf()`).
     *
     * Ownership is proved by the same read as the screen, and the download
     * writes the export audit entry every export writes (12 §1).
     */
    public function billingInvoicePdf(
        Settings $settings,
        BillingOverview $overview,
        InvoiceActions $actions,
        Exporter $exporter,
        int $invoice,
    ): StreamedResponse {
        abort_unless($settings->ownsInvoice((string) tenant()->getTenantKey(), $invoice), 404);

        $detail = $overview->invoice($invoice);
        abort_if($detail === null, 404);

        $credits = $actions->creditsFor($invoice);

        $pdf = Pdf::loadView('documents.invoice', [
            'invoice' => $detail,
            'notes' => $credits['notes'],
            'creditedMinor' => $credits['credited_minor'],
        ])->setPaper('a4')->output();

        return $exporter->file(
            scope: 'Invoice '.$detail['reference'].' — '.$detail['client'],
            contents: $pdf,
            filename: $detail['reference'].'.pdf',
            contentType: 'application/pdf',
            rowCount: count($detail['lines']),
        );
    }

    /**
     * Save all eighteen of board 30's switches in one submit.
     *
     * ONE SUBMIT, ONE AUDIT ENTRY, exactly as the board draws it — a single
     * topbar "Save changes" rather than Features' per-row arm-and-confirm.
     * Nothing here disables a module or changes what a household may do; it
     * decides who is emailed, texted or pushed about something that has already
     * happened, which is a contact preference in the same register as the
     * estate's enquiries mailbox on board 21.
     *
     * THE POSTED ARRAY IS NOT THE ALLOWLIST. `Settings::saveNotifications()`
     * walks its own `NOTIFICATION_EVENTS` and reads each key out of the input,
     * so an extra key in the request body reaches nothing — the same shape as
     * the fixed three-key allowlist on `saveProfile()`.
     */
    public function saveNotifications(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'defaults' => ['present', 'array'],
        ]);

        /*
         * A REFUSAL, SAID ON THE SCREEN (M10 §1). Switching SMS on while no
         * provider is connected is refused by the service before it writes
         * anything, in a sentence the committee can act on — never accepted and
         * then quietly texted to nobody.
         */
        try {
            $settings->saveNotifications($data['defaults'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['defaults' => $refused->getMessage()]);
        }

        return back()->with('success', 'Notification defaults saved.');
    }

    /* ------------------------------------------------------------------ */
    /* the acts */
    /* ------------------------------------------------------------------ */

    /**
     * Save the estate's own contact details.
     *
     * FOUR OF BOARD 21'S SEVEN INPUTS ARE NOT VALIDATED HERE, because they are
     * not accepted at all. The estate name, address, unit count and phase count
     * are the central client record and this estate's own units; the payload
     * marks each of them `editable: false` with the reason, and a request that
     * posted them anyway would have them ignored rather than silently applied.
     */
    public function saveProfile(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'enquiries_email' => ['nullable', 'email', 'max:160'],
            'enquiries_phone' => ['nullable', 'string', 'max:40'],
            'security_provider' => ['required', 'string', 'max:120'],
        ]);

        try {
            $settings->updateProfile($data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['security_provider' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/settings/profile'))
            ->with('success', 'Estate profile saved.');
    }

    /**
     * Switch a feature, or choose a payroll routing.
     *
     * ONE ACTION FOR BOTH CONTROLS, because board 23 draws both on one panel
     * and a page posting to two different endpoints depending on which row was
     * clicked would have to know which rows are segmented. The payload already
     * says — `control` is `switch`, `segmented` or `scale` — so the request
     * carries `enabled` or `option` and this dispatches on which arrived.
     *
     * THE REASON IS REQUIRED IN BOTH SHAPES. Board 23 states it: every toggle
     * change writes an audit record with actor, timestamp AND reason, and a
     * reason nobody typed is not one.
     */
    public function updateFeature(Request $request, string $feature, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:300'],
            'enabled' => ['required_without:option', 'boolean'],
            'option' => ['required_without:enabled', 'string', 'max:24'],
        ]);

        try {
            if (array_key_exists('option', $data)) {
                $settings->setRouting(
                    key: $feature,
                    option: (string) $data['option'],
                    reason: (string) $data['reason'],
                    by: $request->user(),
                );
            } else {
                $settings->setFeature(
                    key: $feature,
                    enabled: $request->boolean('enabled'),
                    reason: (string) $data['reason'],
                    by: $request->user(),
                    tenantKey: (string) tenant()->getTenantKey(),
                );
            }
        } catch (DomainException $refused) {
            /*
             * Against `enabled`, because that is the control the person moved —
             * and where the refusal is about the plan or the ruling rather than
             * the switch, the message says so rather than leaving them toggling
             * something that was never the problem.
             */
            return back()->withErrors(['enabled' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/settings/features'))
            ->with('success', 'Feature settings saved.');
    }

    /**
     * Where a screen lives, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
