<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The seven permission verbs (D-013).
 *
 * `Approve` is deliberately separate from `Update` so that a role may prepare
 * an irreversible act without being able to commit it. It gates the three
 * irreversible acts in this system: payroll approval, period close, and ballot
 * certification.
 */
enum PermissionVerb: string
{
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Approve = 'approve';
    case Export = 'export';
    case Configure = 'configure';
}
