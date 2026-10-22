<?php

declare(strict_types=1);

use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\RoleEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FacilitiesFixture;
use Tests\Support\QueryBudget;

/*
|--------------------------------------------------------------------------
| The role board asks each question once, and gets the same answers — M11 A2
|--------------------------------------------------------------------------
|
| `RoleEditor::board()` draws the Role Access Matrix. For every role it asks whether each
| act would be refused and why, whether the role is at its default, who last edited it,
| who holds it and what it compiles to — a handful of queries a role, sixty to draw the
| Gemini console's roles. While a board is drawn those questions are now asked once, for
| every role together.
|
| The proof is the per-role path, which is unchanged and runs for every write: for each
| role on the board, the row must carry exactly what `refusal()`, `defaultDifference()` and
| the role's own relations say when they are asked one role at a time — across a seeded
| role changed by the Director, a runtime role somebody holds, one an invitation still
| names, one named only in the history of a past assignment, and one nobody has touched.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

it('draws every role\'s row from one read of each fact, identical to asking each role on its own', function (): void {
    $editor = app(RoleEditor::class);
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $suffix = Str::lower(Str::random(6));

    // A seeded role the Director has changed: marked edited, and no longer at its default.
    $dispatcher = Role::named(Role::DISPATCHER);
    $editor->setPermissions($director, $dispatcher, ['cross_tenant_reports' => ['level' => 'view']]);

    // Runtime roles in each of the states the delete refusal distinguishes.
    $held = $editor->create($director, 'Held '.$suffix, ['dashboard' => ['level' => 'view']]);
    $invited = $editor->create($director, 'Invited '.$suffix, ['dashboard' => ['level' => 'view']]);
    $historic = $editor->create($director, 'Historic '.$suffix, ['dashboard' => ['level' => 'view']]);
    $clean = $editor->create($director, 'Clean '.$suffix, ['dashboard' => ['level' => 'view']]);

    $holder = User::factory()->create(['console' => 'gemini', 'status' => 'active']);
    $holder->syncRoles([$held->name]);

    Invitation::query()->create([
        'email' => 'invited.'.$suffix.'@example.com', 'token' => Str::random(64), 'invited_by' => $director->id,
        'role_id' => $invited->id, 'tenant_id' => null, 'expires_at' => now()->addDays(14),
    ]);

    EstateAssignment::query()->create(['user_id' => $holder->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'role_id' => $historic->id, 'is_active' => false]);

    $board = QueryBudget::measure(fn (): array => app(RoleEditor::class)->board($director->fresh()));
    $rows = collect($board['result']['roles'])->keyBy('id');

    expect($rows->keys()->all())->toContain($dispatcher->id, $held->id, $invited->id, $historic->id, $clean->id);

    // Each row, against the per-role path — a fresh editor, outside any board.
    $single = app(RoleEditor::class);

    foreach ($rows as $id => $row) {
        $role = Role::query()->findOrFail($id);
        $difference = $single->defaultDifference($role);

        expect($row['rename_refusal'])->toBe($single->refusal($director, $role, 'rename'), "{$role->name} rename")
            ->and($row['delete_refusal'])->toBe($single->refusal($director, $role, 'delete'), "{$role->name} delete")
            ->and($row['permissions_refusal'])->toBe($single->refusal($director, $role, 'permissions'), "{$role->name} permissions")
            ->and($row['reset_refusal'])->toBe($single->refusal($director, $role, 'reset'), "{$role->name} reset")
            ->and($row['is_default'])->toBe($role->is_system && ($difference['is_default'] ?? false), "{$role->name} at default")
            ->and($row['holders'])->toBe($role->users()->where('console', 'gemini')->count(), "{$role->name} holders")
            ->and($row['permissions'])->toBe($role->permissions()->pluck('name')->sort()->values()->all(), "{$role->name} permissions")
            ->and($row['permissions_edited_by'])->toBe($role->permissions_edited_by_id === null ? null : User::query()->whereKey($role->permissions_edited_by_id)->value('name'), "{$role->name} edited by");
    }

    // The states are real, not five copies of one answer.
    expect($rows[$dispatcher->id]['is_default'])->toBeFalse()
        ->and($rows[$dispatcher->id]['permissions_edited_by'])->toBe($director->name)
        ->and($rows[$held->id]['holders'])->toBe(1)
        ->and($rows[$held->id]['delete_refusal'])->toContain('holds')
        ->and($rows[$invited->id]['delete_refusal'])->toContain('invitation still names')
        ->and($rows[$historic->id]['delete_refusal'])->toContain('named in the record')
        ->and($rows[$clean->id]['delete_refusal'])->toBeNull();

    // And one read of each fact, however many roles the board draws: the holders column and
    // the delete refusal's holders are one grouped count each (the actor's own roles are a
    // third `model_has_roles` read, and are not about the board).
    $holderReads = count(array_filter($board['statements'], static fn (array $s): bool => str_contains($s['sql'], 'group by `model_has_roles`.`role_id`')));
    $invitationReads = count(array_filter($board['statements'], static fn (array $s): bool => str_contains($s['sql'], 'from `invitations`')));

    expect($holderReads)->toBeLessThanOrEqual(2)
        ->and($invitationReads)->toBeLessThanOrEqual(2)
        ->and(QueryBudget::worstRepeat($board))->toBeLessThanOrEqual(2);
});
