<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Console as ConsoleEnum;
use App\Models\Module;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use Illuminate\Console\Command;

/**
 * Renders the seeded role access matrix back out as a grid, so it can be
 * diffed by eye against the wireframe it was parsed from.
 *
 *   php artisan rbac:show
 *   php artisan rbac:show --console=estate
 *   php artisan rbac:show --nav
 */
class RbacMatrixShow extends Command
{
    protected $signature = 'rbac:show
        {--console= : gemini or estate; both if omitted}
        {--nav : show generated navigation per role instead of the grid}';

    protected $description = 'Print the seeded role access matrix or the navigation it generates';

    public function handle(): int
    {
        $consoles = $this->option('console')
            ? [ConsoleEnum::from($this->option('console'))]
            : ConsoleEnum::cases();

        foreach ($consoles as $console) {
            $this->option('nav')
                ? $this->renderNavigation($console)
                : $this->renderGrid($console);
        }

        return self::SUCCESS;
    }

    private function renderGrid(ConsoleEnum $console): void
    {
        $roles = Role::where('console', $console->value)->orderBy('sort')->get();
        $modules = Module::where('console', $console->value)->orderBy('sort')->get();

        $this->newLine();
        $this->info($console->label().' — '.$roles->count().' roles × '.$modules->count().' modules');

        // With its module: a pill that names its own acts (M11 B3) asks it which.
        $access = RoleModuleAccess::with('module')
            ->whereIn('role_id', $roles->pluck('id'))
            ->get()
            ->keyBy(fn (RoleModuleAccess $a) => $a->role_id.':'.$a->module_id);

        $rows = [];

        foreach ($modules as $module) {
            $row = [$module->is_locked_financial ? "🔒 {$module->label}" : $module->label];

            foreach ($roles as $role) {
                $cell = $access->get($role->id.':'.$module->id);
                $row[] = $cell ? $cell->pillLabel() : '?';
            }

            $rows[] = $row;
        }

        $this->table(
            array_merge(['Module'], $roles->map(fn (Role $r) => $r->label)->all()),
            $rows,
        );

        $locked = $modules->where('is_locked_financial', true);

        if ($locked->isNotEmpty()) {
            $this->line('  🔒 = locked financial. The Property Manager may hold no level here (D-010).');
        }
    }

    private function renderNavigation(ConsoleEnum $console): void
    {
        $this->newLine();
        $this->info($console->label().' — navigation generated from the matrix at runtime');

        $rows = Role::where('console', $console->value)
            ->orderBy('sort')
            ->get()
            ->map(function (Role $role) {
                $nav = $role->navigableModules();

                return [
                    $role->label,
                    $role->scope_default->label(),
                    $nav->count(),
                    $nav->pluck('key')->implode(', '),
                ];
            })
            ->all();

        $this->table(['Role', 'Scope', 'Modules', 'Navigation'], $rows);
        $this->line('  A module a role cannot use is ABSENT here, never disabled or greyed.');
    }
}
