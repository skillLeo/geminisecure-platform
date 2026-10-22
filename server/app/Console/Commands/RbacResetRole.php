<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\RoleEditor;
use DomainException;
use Illuminate\Console\Command;

/**
 * Put one seeded role back to GeminiSecure's default permissions (M10 §4).
 *
 * THE DELIBERATE WAY BACK. Since M10 §4 a re-seed keeps whatever the Director
 * changed on a seeded Gemini role, because a deploy that silently undid the
 * client's change would be the worst thing an editable matrix could do. That
 * leaves one question: how does a role get back to the platform's definition
 * when somebody wants it to? On the console, "Reset to GeminiSecure default"
 * (`RoleEditor::resetToDefault()`). On the server, this. It is for when nobody on
 * the console may make the change, for example a Director who narrowed their
 * own role and can no longer grant what the default holds.
 *
 * WITHOUT --confirm IT CHANGES NOTHING. It prints what the reset would add and
 * take away, so the person at the terminal reads the consequence before
 * committing to it. With --confirm it restores the seeded cells, recompiles the
 * role, clears its edit mark (so later re-seeds keep it in step with the grid
 * again), writes a `role.reset_to_default` audit entry and flushes the
 * permission cache after the commit.
 *
 * Any of the fourteen seeded roles. A role made at runtime has no default and
 * is refused by name. The estate's eight always follow the grid, so resetting
 * one only rewrites what the grid already says. Harmless, and occasionally
 * useful after a hand edit to the database.
 *
 * Pure ASCII output, like the gates.
 */
final class RbacResetRole extends Command
{
    protected $signature = 'rbac:reset-role
        {name : the seeded role\'s machine name, e.g. gemini.dispatcher}
        {--confirm : restore it; without this the command only shows what would change}';

    protected $description = 'Put one seeded role back to GeminiSecure\'s default permissions, undoing changes made on the console';

    public function handle(RoleEditor $roles): int
    {
        $name = trim((string) $this->argument('name'));

        $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();

        if ($role === null) {
            $this->error("No role [{$name}]. Seeded role names look like gemini.dispatcher or estate.treasurer.");

            return self::FAILURE;
        }

        $difference = $roles->defaultDifference($role);

        if ($difference === null) {
            $this->error("[{$name}] was made at runtime, not seeded, so it has no GeminiSecure default to go back to.");

            return self::FAILURE;
        }

        $label = (string) ($role->label ?? $role->name);

        $this->line("{$label} ({$role->name})");

        if ($role->permissions_edited_at !== null) {
            $by = $role->permissions_edited_by_id === null
                ? 'an unknown account'
                : (User::query()->whereKey($role->permissions_edited_by_id)->value('name') ?? 'an account since removed');

            $this->line("  changed on the console {$role->permissions_edited_at->toDateTimeString()} UTC by {$by}");
        } else {
            $this->line('  not marked as changed on the console');
        }

        if ($difference['is_default'] && $role->permissions_edited_at === null) {
            $this->info('  already at GeminiSecure\'s default. Nothing to do.');

            return self::SUCCESS;
        }

        if ($difference['add'] === [] && $difference['remove'] === []) {
            $this->line('  the permissions already match the default; the reset would only tidy the cells and clear the mark');
        }

        foreach ($difference['add'] as $permission) {
            $this->line("  + {$permission}");
        }

        foreach ($difference['remove'] as $permission) {
            $this->line("  - {$permission}");
        }

        if (! $this->option('confirm')) {
            $this->warn('Nothing has changed. Run again with --confirm to restore this role to its default.');

            return self::SUCCESS;
        }

        try {
            $roles->resetToDefaultByOperator($role);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored {$label} to GeminiSecure's default. Audited as role.reset_to_default; later re-seeds keep it in step with the grid again.");

        return self::SUCCESS;
    }
}
