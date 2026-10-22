<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The permission levels drawn in both role access matrices.
 *
 * Legend, verbatim from the Estate matrix wireframe (06, lines 575-578):
 *   Full   Create, edit, approve
 *   View   Read-only
 *   Entry  Data entry, no approval
 *   —      No access
 *
 * AND A FIFTH, WHICH NO WIREFRAME DRAWS (M10 §4). The client asks for control
 * "per module and per action", and the four presets cannot say "view and
 * export, and nothing else". `Custom` is a cell whose verbs are listed on the
 * cell itself, in `role_module_access.verbs`. It is a fifth case rather than a
 * widening of the four so that every seeded cell, `rbac:show` and both matrix
 * screens read exactly as they did.
 */
enum AccessLevel: string
{
    case None = 'none';
    case View = 'view';
    case Entry = 'entry';
    case Full = 'full';
    case Custom = 'custom';

    /**
     * The verbs this level grants, before can_approve is applied.
     *
     * `Entry` may create and update but never delete, export or configure —
     * that is what "data entry, no approval" means in practice.
     *
     * `Custom` answers NOTHING here, deliberately. Its verbs are on the cell, and
     * `RoleModuleAccess::grantedVerbs()` is the only place that reads them; a
     * caller that asked the level of a custom cell would be asking the wrong
     * question, and an empty answer fails closed.
     *
     * @return list<PermissionVerb>
     */
    public function verbs(): array
    {
        return match ($this) {
            self::None, self::Custom => [],
            self::View => [PermissionVerb::View],
            self::Entry => [PermissionVerb::View, PermissionVerb::Create, PermissionVerb::Update],
            self::Full => [
                PermissionVerb::View,
                PermissionVerb::Create,
                PermissionVerb::Update,
                PermissionVerb::Delete,
                PermissionVerb::Export,
                PermissionVerb::Configure,
            ],
        };
    }

    /**
     * Does this level put the module in the navigation at all?
     *
     * TRUE FOR `Custom`, AND THAT IS NOT THE ANSWER FOR A CUSTOM CELL. A custom
     * cell is visible only if its own verbs include `view`, which only the cell
     * knows — so every navigation and "reaches these modules" reader asks
     * `RoleModuleAccess::grantsView()` instead, and this stays the level's own
     * answer for the four presets.
     */
    public function isVisible(): bool
    {
        return $this !== self::None;
    }

    /** The pill label as drawn in the wireframe. */
    public function label(): string
    {
        return match ($this) {
            self::None => '—',
            self::View => 'View',
            self::Entry => 'Entry',
            self::Full => 'Full',
            self::Custom => 'Custom',
        };
    }

    /**
     * The preset that grants exactly these verbs, if there is one.
     *
     * So that a custom grid somebody ticks into the shape of "View" is stored as
     * View — and reads as View on both matrices and in `rbac:show` — rather than
     * as a custom cell that happens to say the same thing.
     *
     * @param  list<PermissionVerb>  $verbs
     */
    public static function presetFor(array $verbs): ?self
    {
        $wanted = array_map(static fn (PermissionVerb $verb): string => $verb->value, $verbs);
        sort($wanted);

        foreach ([self::None, self::View, self::Entry, self::Full] as $level) {
            $has = array_map(static fn (PermissionVerb $verb): string => $verb->value, $level->verbs());
            sort($has);

            if ($has === $wanted) {
                return $level;
            }
        }

        return null;
    }
}
