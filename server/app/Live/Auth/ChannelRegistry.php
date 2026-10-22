<?php

declare(strict_types=1);

namespace App\Live\Auth;

use App\Live\Auth\Rules\ApprovalRule;
use App\Live\Auth\Rules\ClaimRule;
use App\Live\Auth\Rules\CommunityRule;
use App\Live\Auth\Rules\EstateFeedRule;
use App\Live\Auth\Rules\EstateGuardsRule;
use App\Live\Auth\Rules\EstatePassesRule;
use App\Live\Auth\Rules\GeminiFeedRule;
use App\Live\Auth\Rules\GuardRule;
use App\Live\Auth\Rules\HouseholdRule;
use App\Live\Auth\Rules\PhaseRule;
use App\Live\Auth\Rules\ResidentRule;
use App\Live\Auth\Rules\Rule;
use App\Live\Auth\Rules\UserRule;
use App\Live\ChannelName;
use InvalidArgumentException;
use Throwable;

/**
 * THE ONE ANSWER TO "MAY THIS PRINCIPAL HOLD THIS CHANNEL?" (M11 Part C, DESIGN §3.4).
 *
 * Both doors ask it — the web session's `POST /live/auth` and the handset's
 * `POST /api/v1/live/auth` — and so does catch-up, which re-authorises every channel a
 * client lists rather than trusting the list (§5.1), and so will the reconcile backstop
 * (§7.4). There is one rule per family (§3.1), and a name the grammar does not read has
 * no rule and is refused.
 *
 * IT TAKES THE BARE NAME — `estate.pp.security`, never `private-live.estate.pp.security`.
 * The prefix is the door's to check (`ChannelName::fromWire()`), because only a door
 * sees a wire name; catch-up and reconcile hold bare names.
 *
 * FAIL CLOSED, AND THE SAME ANSWER WHATEVER THE REASON. A malformed name, a missing
 * tenant, somebody else's record, a missing permission and a rule that throws are all
 * the same `false`; a thrown rule is reported, and the estate it opened is closed again
 * (`EstateScope`).
 *
 * CENTRAL CHECKS BEFORE ANY ESTATE IS OPENED (§3.5). `decideMany()` asks every channel's
 * central half first, and opens an estate only for the channels of it that passed —
 * once per estate for the whole batch, however many channels name it.
 */
final class ChannelRegistry
{
    /**
     * Family => the rule that decides it. The pulse has none: it is public, and no door
     * signs it.
     *
     * @var array<string, class-string<Rule>|null>
     */
    public const RULES = [
        ChannelName::USER => UserRule::class,
        ChannelName::GEMINI_FEED => GeminiFeedRule::class,
        ChannelName::GEMINI_TENANT_FEED => GeminiFeedRule::class,
        ChannelName::GEMINI_RECORD => GeminiFeedRule::class,
        ChannelName::ESTATE_FEED => EstateFeedRule::class,
        ChannelName::ESTATE_RECORD => EstateFeedRule::class,
        ChannelName::GUARD => GuardRule::class,
        ChannelName::ESTATE_GUARDS => EstateGuardsRule::class,
        ChannelName::ESTATE_PASSES => EstatePassesRule::class,
        ChannelName::APPROVAL => ApprovalRule::class,
        ChannelName::HOUSEHOLD => HouseholdRule::class,
        ChannelName::RESIDENT => ResidentRule::class,
        ChannelName::COMMUNITY => CommunityRule::class,
        ChannelName::PHASE => PhaseRule::class,
        ChannelName::CLAIM => ClaimRule::class,
        ChannelName::PULSE_FAMILY => null,
    ];

    /**
     * @param  array<string, Rule>  $overrides  a rule instance per family, in place of the
     *                                          registered one — for a test that needs a rule
     *                                          to misbehave, and for nothing else
     */
    public function __construct(private readonly array $overrides = []) {}

    /** May this principal hold this (bare) channel? */
    public function decide(Principal $principal, string $name): bool
    {
        return $this->decideMany($principal, [$name])[$name] ?? false;
    }

    /**
     * Every name answered, in one pass: central checks for all, then each estate opened
     * once for the channels of it that passed them.
     *
     * @param  list<string>  $names
     * @return array<string, bool>
     */
    public function decideMany(Principal $principal, array $names): array
    {
        $answers = [];
        $rules = [];

        /** @var array<string, list<array{0: string, 1: ChannelName, 2: Rule}>> $estates */
        $estates = [];

        foreach ($names as $name) {
            if (array_key_exists($name, $answers)) {
                continue;
            }

            $answers[$name] = false;

            $channel = ChannelName::parse($name);
            $rule = $channel === null ? null : $this->rule($channel->family, $rules);

            if ($channel === null || $rule === null) {
                continue;
            }

            try {
                if (! $rule->central($principal, $channel)) {
                    continue;
                }
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if (! $rule->needsEstate()) {
                $answers[$name] = true;

                continue;
            }

            if ($channel->tenant !== null) {
                $estates[$channel->tenant][] = [$name, $channel, $rule];
            }
        }

        foreach ($estates as $tenantId => $pending) {
            try {
                EstateScope::run((string) $tenantId, static function () use ($pending, $principal, &$answers): void {
                    foreach ($pending as [$name, $channel, $rule]) {
                        try {
                            $answers[$name] = $rule->estate($principal, $channel);
                        } catch (Throwable $e) {
                            report($e);
                            $answers[$name] = false;
                        }
                    }
                });
            } catch (Throwable $e) {
                report($e);

                foreach ($pending as [$name]) {
                    $answers[$name] = false;
                }
            }
        }

        return $answers;
    }

    /**
     * The streams a (bare) channel's cursor follows, or none for a name that does not parse.
     *
     * @return list<string>
     */
    public function streamsFor(string $name): array
    {
        return ChannelName::parse($name)?->streams() ?? [];
    }

    /**
     * The channels a handset holds by being who it is — what `live.config` lists: a
     * guard's own, its estate's guards and passes; a resident's own, its claim, and once
     * active its household, community and phase. Each one is then asked of the rules, so
     * the list can never offer a channel the grant would refuse.
     *
     * @return list<string>
     */
    public function handsetChannels(Principal $principal): array
    {
        $candidates = [];

        try {
            if ($principal->guard !== null && $principal->guard->tenant_id !== null) {
                $tenant = (string) $principal->guard->tenant_id;

                $candidates = [
                    ChannelName::guard($tenant, $principal->guard->id),
                    ChannelName::estateGuards($tenant),
                    ChannelName::estatePasses($tenant),
                ];
            } elseif ($principal->resident !== null) {
                $account = $principal->resident;
                $tenant = $account->tenant_id;

                $candidates[] = ChannelName::resident($tenant, $account->id);

                if ($account->claim_id !== null) {
                    $candidates[] = ChannelName::claim($tenant, $account->claim_id);
                }

                $home = HouseholdRule::home($principal, $tenant);

                if ($home !== null) {
                    $candidates[] = ChannelName::household($tenant, $home['household']);
                    $candidates[] = ChannelName::community($tenant);

                    if ($home['block'] !== null) {
                        $candidates[] = ChannelName::phase($tenant, ChannelName::phaseKey($home['block']));
                    }
                }
            }
        } catch (InvalidArgumentException) {
            // An estate key the grammar cannot carry has no channels at all.
            return [];
        }

        return array_keys(array_filter($this->decideMany($principal, $candidates)));
    }

    /**
     * One instance per rule class for this pass, so a rule's memo lasts one decision.
     *
     * @param  array<class-string<Rule>, Rule>  $rules
     */
    private function rule(string $family, array &$rules): ?Rule
    {
        if (isset($this->overrides[$family])) {
            return $this->overrides[$family];
        }

        $class = self::RULES[$family] ?? null;

        if ($class === null) {
            return null;
        }

        return $rules[$class] ??= new $class;
    }
}
