<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\EstateSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;

/**
 * "TRUST THE MAIN RESIDENT'S INVITES" — one estate setting, off by default (household invites, stage 2;
 * DESIGN §5.6, §7.4; D-ACCEPT-2).
 *
 * Off, every member a primary resident invites waits for one tap in Unit claims. On, a member who signs in
 * with the exact address the primary invited is linked at once (`ResidentInviteAcceptance::mayTrust` holds
 * the rest of the conditions). The estate office's own invites always wait.
 *
 * NOT A CATALOGUE FEATURE. The package builder sells what is in the catalogue; an estate's own rule about
 * its own register is not for sale, so it is a column on `estate_settings`, never mass-assigned, and written
 * only here, with the reason the person typed.
 */
final class ResidentInviteTrust
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{enabled: bool, label: string, sentences: list<string>} */
    public function shape(): array
    {
        return [
            'enabled' => (bool) EstateSetting::current()->trust_primary_invites,
            'label' => 'Trust the main resident’s invites',
            'sentences' => [
                'Off (recommended): every member a primary resident invites waits for one tap in Unit claims.',
                'On: a member who signs in with the exact address the primary resident invited is linked at once, without review.',
                'The estate office’s own invites always wait for review. Nobody who is not approved and active is admitted at the gate. Every link is audited.',
            ],
        ];
    }

    public function set(bool $on, string $reason, User $by): void
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 4) {
            throw new DomainException('Say why this is changing — the change is recorded with your name, the time and your reason.');
        }

        $settings = EstateSetting::current();
        $was = (bool) $settings->trust_primary_invites;

        if ($was === $on) {
            throw new DomainException($on
                ? 'The main resident’s invites are already trusted. Nothing was changed.'
                : 'The main resident’s invites already wait for review. Nothing was changed.');
        }

        $settings->forceFill(['trust_primary_invites' => $on])->save();

        $this->audit->record(
            action: 'estate.settings.invite_trust_changed',
            entityType: 'estate_setting',
            entityId: 'trust_primary_invites',
            before: ['trust_primary_invites' => $was],
            after: ['trust_primary_invites' => $on, 'reason' => $reason],
            actor: $by,
            tenantId: (string) tenant()?->getTenantKey(),
        );
    }
}
