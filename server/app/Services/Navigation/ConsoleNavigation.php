<?php

declare(strict_types=1);

namespace App\Services\Navigation;

use App\Enums\Console;
use App\Models\Module;
use App\Models\RoleModuleAccess;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Builds a user's navigation from the role access matrix, at runtime.
 *
 * Cross-cutting rule 9: a module a role cannot use is ABSENT from the
 * navigation, not disabled and not greyed. This returns only what the user may
 * reach, so there is no code path by which a forbidden module could render at
 * all — the front end is never handed one to hide.
 *
 * A user with two roles gets the union of their access, taking the highest
 * level where the two disagree. Two roles must never combine to LESS than
 * either grants alone, which is what a naive intersection would produce.
 */
class ConsoleNavigation
{
    /**
     * @return list<array{key: string, label: string, section: string|null, href: string, active: bool}>
     */
    public function for(User $user, Console $console): array
    {
        $roleIds = $user->roles->pluck('id');

        if ($roleIds->isEmpty()) {
            return [];
        }

        $modules = Module::where('console', $console->value)
            ->orderBy('sort')
            ->get()
            ->keyBy('id');

        $access = RoleModuleAccess::whereIn('role_id', $roleIds)
            ->whereIn('module_id', $modules->keys())
            ->get();

        // "Grants view", read off the cell: a custom cell (M10 §4) holding
        // export alone must not put its module in the sidebar.
        return $access
            ->filter(fn (RoleModuleAccess $a) => $a->grantsView())
            ->groupBy('module_id')
            ->map(fn ($cells, $moduleId) => $modules[$moduleId])
            ->sortBy('sort')
            ->map(fn (Module $module) => [
                'key' => $module->key,
                'label' => $module->label,
                'section' => $module->section,
                'href' => $this->hrefFor($module),
                'active' => $this->isActive($module),
            ])
            ->values()
            ->all();
    }

    /**
     * Route name convention: gemini.<module_key> / estate.<module_key>.
     *
     * Falls back to '#' for a module whose screen is not built yet, so the
     * sidebar renders truthfully during a phased build rather than throwing on
     * a missing route.
     *
     * AND FOR A ROUTE THAT NEEDS A PARAMETER THIS SERVICE CANNOT SUPPLY, which
     * is a sharper edge than it looks. This runs from `HandleInertiaRequests`
     * for EVERY signed-in user on every full page load, and it holds one thing
     * about a module: its key. An estate route carries the estate's identity on
     * the host in production and in the path locally — `{tenant}` either way —
     * so `route()` on one throws `UrlGenerationException`, out of a shared
     * middleware, on every screen in the console at once.
     *
     * That is not hypothetical. `estate.reports` was the first estate route
     * whose name matched its module key exactly, and landing it turned every
     * estate screen into a 500 until this method was taught to say no. Every
     * other estate module is grouped — `estate.residents.index`,
     * `estate.payroll.runs` — so `Route::has()` answered false and the '#'
     * branch hid the problem for thirty-nine screens.
     *
     * `parameterNames()` covers domain parameters as well as path ones, which
     * `Route::has()` does not, and asking is better than catching: the estate
     * sidebar is drawn from `EstateNavigation`, which knows the tenant and
     * builds these paths properly, so a '#' here costs nothing that is rendered.
     * D-069.
     */
    private function hrefFor(Module $module): string
    {
        $name = "{$module->console->value}.{$module->key}";

        $route = Route::getRoutes()->getByName($name);

        if ($route === null || $route->parameterNames() !== []) {
            return '#';
        }

        return route($name);
    }

    private function isActive(Module $module): bool
    {
        $name = "{$module->console->value}.{$module->key}";

        return request()->routeIs($name) || request()->routeIs("{$name}.*");
    }
}
