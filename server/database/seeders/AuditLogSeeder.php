<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AuditEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A short history so the audit screen has something real to render.
 *
 * Written directly rather than through AuditLogger because that service reads
 * the authenticated user and the request, neither of which exists in a seeder.
 */
class AuditLogSeeder extends Seeder
{
    public function run(): void
    {
        $director = User::where('email', 'director@geminisecurity.test')->first();
        $estates = Tenant::estates();

        if (! $director || $estates->isEmpty()) {
            $this->command->warn('Director or estates missing; skipping audit log seed.');

            return;
        }

        $events = [
            ['tenant.provisioned', 'Tenant', $estates->first()->getTenantKey(), null],
            ['tenant.provisioned', 'Tenant', $estates->last()->getTenantKey(), null],
            ['role.permissions_synced', 'Role', 'estate.treasurer', $estates->first()->getTenantKey()],
            ['user.role_assigned', 'User', (string) $director->id, null],
            ['guard.licence_flagged', 'Guard', 'PSRA-004498', $estates->first()->getTenantKey()],
        ];

        foreach ($events as $i => [$action, $entityType, $entityId, $tenantId]) {
            if (AuditEntry::where('action', $action)->where('entity_id', $entityId)->exists()) {
                continue;
            }

            AuditEntry::create([
                'tenant_id' => $tenantId,
                'actor_id' => $director->id,
                'actor_name' => $director->name,
                'actor_role' => 'Director',
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip' => '127.0.0.1',
                'created_at' => now()->subHours(count($events) - $i),
            ]);
        }
    }
}
