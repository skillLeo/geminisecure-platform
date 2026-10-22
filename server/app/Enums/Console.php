<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two web consoles. A Gemini role and an estate role never appear in the
 * same navigation, and no role belongs to both.
 *
 * The Resident and Guard surfaces are mobile and have implicit single-role
 * models (a resident of a household; an officer on shift). Neither has a role
 * access matrix and neither offers in-app role switching, so they are not
 * consoles for RBAC purposes.
 */
enum Console: string
{
    case Gemini = 'gemini';
    case Estate = 'estate';

    public function label(): string
    {
        return match ($this) {
            self::Gemini => 'Gemini Console',
            self::Estate => 'Estate Console',
        };
    }
}
