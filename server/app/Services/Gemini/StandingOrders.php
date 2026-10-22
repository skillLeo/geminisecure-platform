<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Live\Channels;
use App\Live\Events\RecordLive;
use App\Models\Guard;
use App\Models\Post;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\OperatorEstates;
use App\Services\Push\PushAnnouncer;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writing standing orders, and the cycle that makes them binding — board 25's
 * "New order set" (12 §2, item 28).
 *
 * THE CYCLE. An order set is published at version 1 with the date it takes
 * effect. A post-specific set is then acknowledged by each guard who stands that
 * post, from their handset, AGAINST THE VERSION THEY READ. A revision publishes
 * the next version — with a note of what changed — and every acknowledgement of
 * the version before stops counting, because a guard who agreed to last month's
 * instructions has not agreed to this month's. A periodic review that changes
 * nothing is recorded as a review and does not reset anyone's acknowledgement.
 *
 * EVERY VERSION IS KEPT WHOLE (`standing_order_versions`), so what a guard
 * acknowledged can always be read, not only numbered.
 *
 * NO BACKDATING. Orders take effect today or later: a set dated last week would
 * instruct guards retrospectively about nights they have already worked.
 *
 * COMPANY-WIDE ORDERS ARE THE COMPANY'S. A role scoped to assigned sites may
 * write the orders for a post it covers; the general orders and the emergency
 * annex apply at every client, and only a role that covers every client may
 * publish or revise them. They are part of induction rather than acknowledged
 * per post, which is what the library already says of them.
 */
final class StandingOrders
{
    /** @var array<string, string> */
    public const CATEGORIES = [
        'general' => 'Company-wide general orders',
        'emergency' => 'Emergency procedures (company-wide)',
        'post_specific' => 'Orders for one post',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Publish a new order set at version 1.
     *
     * @param  array<string, mixed>  $fields
     */
    public function create(array $fields, User $by): int
    {
        $category = (string) ($fields['category'] ?? '');

        if (! array_key_exists($category, self::CATEGORIES)) {
            throw new DomainException('Choose what kind of orders these are.');
        }

        $body = $this->body($fields);
        $tenantId = null;
        $postId = null;

        if ($category === 'post_specific') {
            $post = Post::query()->with('estate')->find((int) ($fields['post_id'] ?? 0));

            if ($post === null || ! $by->canAccessEstate((string) $post->tenant_id)) {
                throw new DomainException('That post is not one this role covers.');
            }

            if (DB::connection('mysql')->table('standing_order_sets')->where('post_id', $post->id)->exists()) {
                throw new DomainException($post->name.' already has its own orders. Revise that set, so the guards on post acknowledge one current version rather than two sets.');
            }

            $tenantId = (string) $post->tenant_id;
            $postId = $post->id;
            $title = ($post->estate->name ?? $tenantId).' — '.$post->name;
            $summary = 'Post-specific orders';
        } else {
            $this->refuseIfScoped($by);

            $title = trim((string) ($fields['title'] ?? ''));
            $summary = trim((string) ($fields['summary'] ?? ''));

            if ($title === '' || $summary === '') {
                throw new DomainException('Company-wide orders need a title and a one-line summary of where they apply.');
            }
        }

        // On the calendar of where the orders apply, now that that is known (bug hunt B106).
        $effectiveOn = $this->effectiveOn($fields, $tenantId);

        return DB::connection('mysql')->transaction(function () use ($title, $category, $summary, $tenantId, $postId, $body, $effectiveOn, $by): int {
            $now = Carbon::now();

            $id = (int) DB::connection('mysql')->table('standing_order_sets')->insertGetId([
                'title' => mb_substr($title, 0, 140),
                'category' => $category,
                'summary' => mb_substr($summary, 0, 190),
                'tenant_id' => $tenantId,
                'post_id' => $postId,
                'version' => 1,
                'body' => $body,
                'effective_on' => $effectiveOn,
                'reviewed_on' => self::today($tenantId),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->keepVersion($id, 1, mb_substr($title, 0, 140), mb_substr($summary, 0, 190), $body, $effectiveOn, null, $by);

            $this->audit->record(
                action: 'operations.orders_published',
                entityType: 'StandingOrderSet',
                entityId: (string) $id,
                after: ['title' => $title, 'category' => $category, 'version' => 1, 'effective_on' => $effectiveOn],
                tenantId: $tenantId,
            );

            return $id;
        });
    }

    /**
     * Publish the next version. Every acknowledgement of the version before
     * stops counting, by construction: they are kept, against the old number.
     *
     * @param  array<string, mixed>  $fields
     */
    public function revise(int $setId, array $fields, User $by): int
    {
        $set = $this->writableSet($setId, $by);

        $body = $this->body($fields);
        $effectiveOn = $this->effectiveOn($fields, $set->tenant_id === null ? null : (string) $set->tenant_id);
        $note = trim((string) ($fields['change_note'] ?? ''));

        if ($note === '') {
            throw new DomainException('Say what changed. A guard asked to acknowledge a new version is entitled to know what is different about it.');
        }

        if ($body === (string) $set->body) {
            throw new DomainException('The text is unchanged. A review that changes nothing is recorded as a review, and does not ask every guard on post to sign again.');
        }

        $summary = $set->post_id === null ? trim((string) ($fields['summary'] ?? $set->summary)) : (string) $set->summary;
        $version = (int) $set->version + 1;

        DB::connection('mysql')->transaction(function () use ($set, $body, $effectiveOn, $note, $summary, $version, $by): void {
            DB::connection('mysql')->table('standing_order_sets')->where('id', $set->id)->update([
                'version' => $version,
                'body' => $body,
                'summary' => mb_substr($summary === '' ? (string) $set->summary : $summary, 0, 190),
                'effective_on' => $effectiveOn,
                'reviewed_on' => self::today($set->tenant_id === null ? null : (string) $set->tenant_id),
                'updated_at' => Carbon::now(),
            ]);

            $this->keepVersion((int) $set->id, $version, (string) $set->title, mb_substr($summary === '' ? (string) $set->summary : $summary, 0, 190), $body, $effectiveOn, mb_substr($note, 0, 300), $by);

            $this->audit->record(
                action: 'operations.orders_revised',
                entityType: 'StandingOrderSet',
                entityId: (string) $set->id,
                before: ['version' => (int) $set->version],
                after: ['version' => $version, 'effective_on' => $effectiveOn, 'change_note' => $note],
                tenantId: $set->tenant_id,
            );
        });

        return $version;
    }

    /** A periodic review that changes nothing. No acknowledgement is reset. */
    public function markReviewed(int $setId, User $by): void
    {
        $set = $this->writableSet($setId, $by);

        // The day it was reviewed where the orders apply — not the server's UTC date, which is tomorrow from 19:00 in Kingston (bug hunt B106).
        $today = self::today($set->tenant_id === null ? null : (string) $set->tenant_id);

        DB::connection('mysql')->table('standing_order_sets')->where('id', $set->id)->update([
            'reviewed_on' => $today,
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record(
            action: 'operations.orders_reviewed',
            entityType: 'StandingOrderSet',
            entityId: (string) $set->id,
            after: ['version' => (int) $set->version, 'reviewed_on' => $today],
            tenantId: $set->tenant_id,
        );
    }

    /**
     * One set, its versions, and who has acknowledged which. Null outside the
     * viewer's scope, the same as for an id that does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $setId, User $viewer): ?array
    {
        $set = DB::connection('mysql')->table('standing_order_sets')
            ->leftJoin('tenants', 'tenants.id', '=', 'standing_order_sets.tenant_id')
            ->leftJoin('posts', 'posts.id', '=', 'standing_order_sets.post_id')
            ->where('standing_order_sets.id', $setId)
            ->select('standing_order_sets.*', 'tenants.name as estate', 'posts.name as post')
            ->first();

        if ($set === null || ($set->tenant_id !== null && ! $viewer->canAccessEstate((string) $set->tenant_id))) {
            return null;
        }

        $versions = DB::connection('mysql')->table('standing_order_versions')
            ->where('standing_order_set_id', $setId)
            ->orderByDesc('version')
            ->get();

        $acks = DB::connection('mysql')->table('standing_order_acknowledgements')
            ->join('guards', 'guards.id', '=', 'standing_order_acknowledgements.guard_id')
            ->where('standing_order_set_id', $setId)
            ->orderByDesc('acknowledged_at')
            ->get(['guards.id as guard_id', 'guards.full_name', 'guards.tenant_id', 'standing_order_acknowledgements.version', 'standing_order_acknowledgements.acknowledged_at']);

        /*
         * WHEN A GUARD ACKNOWLEDGED, ON THE CLOCK OF THE ESTATE THEY STAND AT (M4 §3.1, M11 §4.1). The
         * column is a true UTC instant; parsed and printed as it was, an acknowledgement at 8 PM in Kingston
         * read 1:00 AM the next day. A company-wide set is acknowledged across estates, so a time names
         * its zone where it is not Gemini's own; a day is that estate's day, and needs no label.
         */
        $acknowledgedAt = static fn (object $ack): Carbon => SiteTime::local(
            Carbon::parse((string) $ack->acknowledged_at, 'UTC'),
            $ack->tenant_id === null ? null : (string) $ack->tenant_id,
        );

        $onPost = $set->post_id === null ? collect() : Guard::query()
            ->where('post_id', $set->post_id)
            ->whereNotIn('status', ['inactive'])
            ->orderBy('full_name')
            ->get();

        $current = (int) $set->version;

        return [
            'id' => (int) $set->id,
            'title' => (string) $set->title,
            'category' => (string) $set->category,
            'category_label' => self::CATEGORIES[$set->category] ?? (string) $set->category,
            'company_wide' => $set->post_id === null,
            'where' => $set->post_id === null ? 'Every post at every client' : $set->estate.' — '.$set->post,
            'summary' => (string) $set->summary,
            'version' => $current,
            'body' => (string) $set->body,
            'effective_on' => Carbon::parse((string) $set->effective_on)->format('M j, Y'),
            'reviewed_on' => $set->reviewed_on === null ? 'Not yet reviewed' : Carbon::parse((string) $set->reviewed_on)->format('M j, Y'),

            // The earliest a revision may take effect: today where these orders apply (bug hunt B106).
            'today' => self::today($set->tenant_id === null ? null : (string) $set->tenant_id),

            'versions' => $versions->map(static fn (object $v): array => [
                'version' => (int) $v->version,
                'effective_on' => Carbon::parse((string) $v->effective_on)->format('M j, Y'),
                'change_note' => $v->change_note,
                'published_by' => $v->published_by_name ?? 'Not recorded',
                'body' => (string) $v->body,
            ])->all(),
            'guards_on_post' => $onPost->map(function (Guard $guard) use ($acks, $current, $acknowledgedAt): array {
                $mine = $acks->where('guard_id', $guard->id);
                $currentAck = $mine->firstWhere('version', $current);
                $last = $mine->first();

                return [
                    'name' => $guard->full_name,
                    'acknowledged' => $currentAck !== null,
                    'line' => match (true) {
                        $currentAck !== null => 'Acknowledged version '.$current.' on '.$acknowledgedAt($currentAck)->format('M j, Y'),
                        $last !== null => 'Acknowledged version '.$last->version.' only — not the version in force',
                        default => 'Has not acknowledged these orders',
                    },
                    // A licence is good through its expiry date on the site's calendar (B008).
                    'can_stand' => $guard->status === 'active'
                        && ($guard->psra_expires_on === null || $guard->licenceDaysLeft() >= 0),
                ];
            })->all(),
            'acknowledgements' => $acks->map(static fn (object $a): array => [
                'guard' => (string) $a->full_name,
                'version' => (int) $a->version,
                'at' => $acknowledgedAt($a)->format('M j, Y g:i A').SiteTime::label($acknowledgedAt($a)),
                'current' => (int) $a->version === $current,
            ])->all(),
            'writable' => $set->tenant_id === null
                ? $viewer->widestScope() !== AccessScope::AssignedSites
                : $viewer->canAccessEstate((string) $set->tenant_id),
        ];
    }

    /**
     * What a guard's handset shows: the company-wide orders to read, and the
     * orders for the post they stand, with whether they have acknowledged the
     * version in force. Nothing here carries an amount.
     *
     * @return list<array<string, mixed>>
     */
    public function forGuard(Guard $guard): array
    {
        $sets = DB::connection('mysql')->table('standing_order_sets')
            ->where(function ($query) use ($guard): void {
                $query->whereNull('post_id');

                if ($guard->post_id !== null) {
                    $query->orWhere('post_id', $guard->post_id);
                }
            })
            ->orderByRaw('post_id IS NULL')
            ->orderBy('title')
            ->get();

        $acknowledged = DB::connection('mysql')->table('standing_order_acknowledgements')
            ->where('guard_id', $guard->id)
            ->get()
            ->map(static fn (object $a): string => $a->standing_order_set_id.'@'.$a->version)
            ->flip();

        return $sets->map(static fn (object $set): array => [
            'id' => (int) $set->id,
            'title' => (string) $set->title,
            'version' => (int) $set->version,
            'effective_on' => (string) $set->effective_on,
            'body' => (string) $set->body,
            'requires_acknowledgement' => $set->post_id !== null,
            'acknowledged' => $set->post_id !== null && $acknowledged->has($set->id.'@'.$set->version),
        ])->all();
    }

    /**
     * A guard acknowledging the orders for their post, against the version they
     * read. Acknowledging the same version twice is the same acknowledgement.
     *
     * @return array{set: int, version: int, acknowledged_at: string}
     */
    public function acknowledge(Guard $guard, int $setId, int $version): array
    {
        $set = DB::connection('mysql')->table('standing_order_sets')->where('id', $setId)->first();

        if ($set === null || $set->post_id === null || (int) $set->post_id !== (int) $guard->post_id) {
            throw new DomainException('These are not the orders for your post.');
        }

        if ((int) $set->version !== $version) {
            throw new DomainException('These orders have been revised to version '.$set->version.'. Read the current version before acknowledging it.');
        }

        // Lapsed on the site's calendar, not before the server's UTC date (B008).
        if ($guard->status !== 'active' || ($guard->psra_expires_on !== null && $guard->licenceDaysLeft() < 0)) {
            throw new DomainException('Orders for a post are acknowledged by a guard who can stand it. Your record shows you cannot at present.');
        }

        $existing = DB::connection('mysql')->table('standing_order_acknowledgements')
            ->where('standing_order_set_id', $setId)
            ->where('guard_id', $guard->id)
            ->where('version', $version)
            ->first();

        if ($existing === null) {
            $now = Carbon::now();

            DB::connection('mysql')->table('standing_order_acknowledgements')->insert([
                'standing_order_set_id' => $setId,
                'guard_id' => $guard->id,
                'version' => $version,
                'acknowledged_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $at = $now;
        } else {
            $at = Carbon::parse((string) $existing->acknowledged_at);
        }

        return ['set' => $setId, 'version' => $version, 'acknowledged_at' => $at->toIso8601String()];
    }

    /* ------------------------------------------------------------------ */

    /** @param  array<string, mixed>  $fields */
    private function body(array $fields): string
    {
        $body = trim((string) ($fields['body'] ?? ''));

        if (mb_strlen($body) < 20) {
            throw new DomainException('Write the orders out. A guard is held to this text, and a line of shorthand is not an instruction anybody can be held to.');
        }

        return $body;
    }

    /**
     * Today where a set of orders applies, as a date: the post's estate's for a
     * post's orders, the operations room's for company-wide ones.
     *
     * NOT THE SERVER'S UTC DATE (bug hunt B106). "Today or later" was judged, and
     * reviews were dated, by `Carbon::today()` — UTC — so from 19:00 in Kingston
     * the office could not publish orders taking effect today, and a review done
     * that evening was recorded as done tomorrow. The forms read the same date as
     * `today`, so the screen offers exactly what this accepts.
     */
    public static function today(?string $tenantId): string
    {
        return SiteTime::today($tenantId)->toDateString();
    }

    /** @param  array<string, mixed>  $fields */
    private function effectiveOn(array $fields, ?string $tenantId): string
    {
        $typed = trim((string) ($fields['effective_on'] ?? ''));

        // A calendar date, read as typed: the digits the office chose, not a moment moved into a zone.
        $on = $typed === '' ? self::today($tenantId) : Carbon::parse($typed)->toDateString();

        if ($on < self::today($tenantId)) {
            throw new DomainException('Orders take effect today or later. Dating them earlier would instruct guards about shifts they have already worked.');
        }

        return $on;
    }

    private function writableSet(int $setId, User $by): object
    {
        $set = DB::connection('mysql')->table('standing_order_sets')->where('id', $setId)->first();

        if ($set === null) {
            throw new DomainException('That order set is not on record.');
        }

        if ($set->tenant_id === null) {
            $this->refuseIfScoped($by);
        } elseif (! $by->canAccessEstate((string) $set->tenant_id)) {
            throw new DomainException('Those orders are for a post this role does not cover.');
        }

        return $set;
    }

    private function refuseIfScoped(User $by): void
    {
        if ($by->widestScope() === AccessScope::AssignedSites) {
            throw new DomainException('Company-wide orders apply at every client, so they are published and revised by a role that covers every client. You can write the orders for a post you cover.');
        }
    }

    private function keepVersion(int $setId, int $version, string $title, string $summary, string $body, string $effectiveOn, ?string $note, User $by): void
    {
        DB::connection('mysql')->table('standing_order_versions')->insert([
            'standing_order_set_id' => $setId,
            'version' => $version,
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
            'effective_on' => $effectiveOn,
            'change_note' => $note,
            'published_by' => $by->getKey(),
            'published_by_name' => $by->name,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // The guards these orders apply to, even with the app closed (M4 §3.2). Queued; it never throws.
        app(PushAnnouncer::class)->ordersPublished($setId, $title);

        /*
         * …and every screen showing them, now (M11 §2, D-175): the Guard App's orders and its dashboard banner on every
         * guard of the estate, and Gemini's standing-orders pages. Company-wide orders apply at every client, so they
         * are told to each one.
         */
        $tenant = DB::connection('mysql')->table('standing_order_sets')->where('id', $setId)->value('tenant_id');
        $estates = $tenant === null ? OperatorEstates::estates()->map(static fn ($e): string => (string) $e->getTenantKey())->all() : [(string) $tenant];

        foreach ($estates as $estate) {
            RecordLive::tell('orders', $setId, $estate, static fn (): array => [Channels::estateGuards($estate), ...Channels::gemini('workforce', $estate)]);
        }
    }
}
