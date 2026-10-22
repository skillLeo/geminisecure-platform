<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\Estate\Phase;
use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\OperatorEstates;
use App\Support\SiteTime;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * WHERE A CLIENT'S POSTS AND CHECKPOINTS ARE — the geofence editor (M11 §6), Operations → Sites & geofences.
 *
 * A post had its surveyed position and geofence radius on the row (the clock-in's geofence check reads them) and
 * nothing to set them from but a seeder; a checkpoint had no position at all. This is where they are placed on a
 * map: a post's position and the radius a guard must clock in within, and each checkpoint where it stands. The
 * guard's checkpoint map, the Gemini dispatch map and the estate's Security map all draw what is set here.
 *
 * WITHIN THE VIEWER'S SCOPE, and never the demonstration estate (OperatorEstates): a role on assigned sites sees
 * and places only those. Every placement is audited with the position before and after — moving a geofence
 * changes who can clock in, and that is a decision somebody must be able to account for.
 *
 * THE ONLY WAY A GEOFENCE IS MADE (M12 §1.1, §1.5). A post is added here, placed here, and moved here — each with the
 * reason it was done, in the audit entry beside who, when, and the position before and after. So is the ESTATE's own
 * fence, the one a clock-in falls back to when the rostered post has none, and the number a guard at the gate
 * calls for a supervisor. Nothing else writes a position: the store-review estate's seeded gate is the one
 * exception (M26: no seeder places a Phoenix Park post any more; a position comes from somebody standing there).
 *
 * POSTS AS THE ESTATE HAS THEM (M26, M27, D-225). A post carries the other names the estate uses for it ("Gate 5, Phase
 * 5 Gate"), set here and shown wherever the post is named. A post that does not exist is removed here: deleted when
 * nothing was ever done at it, retired (kept for its history, gone from every list) when something was. A patrol is
 * not a post: it is a shift's duty at the gate it starts from (`Shift::DUTY_PATROL`), clocked in inside that gate's
 * fence, and evidenced by the estate's patrol checkpoints and the on duty position trail.
 *
 * THE ESTATE'S PATROL ROUTE (M27 §4). Its checkpoints belong to the estate (no post), in an order the Director sets,
 * each with the phase it stands in. They can be made fifteen at once with no position, their tags printed together,
 * mounted, and placed afterwards from the installers' messages, pasted in one go and confirmed here before anything is
 * saved. How long after one checkpoint the next is due is the Director's setting per estate.
 */
class SiteGeography
{
    /**
     * A geofence tighter than this is not a place a phone's fix can prove anybody is in. (It was five; the clock-in
     * now counts up to 50 m of a fix's accuracy as margin, but a circle smaller than a gatehouse still misleads
     * whoever reads the map.)
     */
    public const MIN_RADIUS_M = 10;

    /** Nor wider than a whole estate. */
    public const MAX_RADIUS_M = 2_000;

    /** What a post is: the roster's own three kinds. */
    public const POST_TYPES = ['gate' => 'Gate', 'patrol' => 'Patrol route', 'relief' => 'Relief'];

    /**
     * What a NEW post may be (M27): a gate or a relief. A patrol is a shift's duty at the gate it starts from; the post
     * of kind "patrol route" is the older shape, kept for the estates that have one, and never offered again.
     */
    public const NEW_POST_TYPES = ['gate' => 'Gate', 'relief' => 'Relief'];

    /** The patrol interval the Director may set, in minutes between one checkpoint and the next (M27 §4.4). */
    public const MIN_PATROL_INTERVAL = 5;

    public const MAX_PATROL_INTERVAL = 240;

    /** A pasted list of checkpoints is a route, not a census: at most this many lines at once. */
    public const MAX_CHECKPOINTS_AT_ONCE = 60;

    /** A checkpoint is a tag on a wall: its circle is a room, not an estate (M21 §1.1). */
    public const MAX_CHECKPOINT_RADIUS_M = 200;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The editor's board: the estates this viewer may place, and one estate's posts and checkpoints.
     *
     * @return array{estates: list<array{id: string, name: string}>, estate: string|null, posts: list<array<string, mixed>>, checkpoints: list<array<string, mixed>>, estateSeesGuards: bool|null, site: array<string, mixed>|null, phases?: list<string>, patrolInterval?: array<string, mixed>}
     */
    public function board(User $viewer, ?string $estateId): array
    {
        $estates = $this->estates($viewer);
        $ids = array_column($estates, 'id');
        $estate = $estateId !== null && in_array($estateId, $ids, true) ? $estateId : ($ids[0] ?? null);

        if ($estate === null) {
            return ['estates' => $estates, 'estate' => null, 'posts' => [], 'checkpoints' => [], 'estateSeesGuards' => null, 'site' => null];
        }

        $posts = Post::query()->where('tenant_id', $estate)->where('is_active', true)->orderBy('name')->get()
            ->map(static fn (Post $post): array => [
                'id' => (int) $post->id,
                'name' => (string) $post->name,
                'also_called' => $post->also_called,
                'label' => $post->label(),
                'type' => (string) $post->type,
                'lat' => $post->latitude === null ? null : (float) $post->latitude,
                'lng' => $post->longitude === null ? null : (float) $post->longitude,
                'radius_m' => (int) ($post->geofence_radius_m ?? self::MIN_RADIUS_M),
            ])->values()->all();

        // The estate's route first (no post), then any older route hanging from a post, each in its own order.
        $checkpoints = DB::connection('mysql')->table('patrol_checkpoints')
            ->where('tenant_id', $estate)->where('is_active', true)
            ->orderByRaw('post_id IS NOT NULL')->orderBy('post_id')->orderBy('sequence')->orderBy('id')
            ->get(['id', 'label', 'phase', 'sequence', 'post_id', 'latitude', 'longitude', 'radius_m'])
            ->map(static fn (object $c): array => [
                'id' => (int) $c->id,
                'label' => (string) $c->label,
                'phase' => $c->phase,
                'sequence' => (int) $c->sequence,
                'post_id' => $c->post_id === null ? null : (int) $c->post_id,
                'lat' => $c->latitude === null ? null : (float) $c->latitude,
                'lng' => $c->longitude === null ? null : (float) $c->longitude,
                'radius_m' => (int) ($c->radius_m ?? PatrolCheckpoint::DEFAULT_RADIUS_M),
            ])->values()->all();

        // The contract setting (TRACK-01): null when the client has no contract to hold it yet.
        $sees = DB::connection('mysql')->table('subscriptions')->where('tenant_id', $estate)->value('estate_sees_guard_positions');

        // The estate's own fence — the fallback for a post with none — and its supervisor's number (M12 §1).
        $site = DB::connection('mysql')->table('estate_sites')->where('tenant_id', $estate)->first();

        return [
            'estates' => $estates,
            'estate' => $estate,
            'posts' => $posts,
            'checkpoints' => $checkpoints,
            'estateSeesGuards' => $sees === null ? null : (bool) $sees,
            'site' => [
                'lat' => $site?->latitude === null ? null : (float) $site->latitude,
                'lng' => $site?->longitude === null ? null : (float) $site->longitude,
                'radius_m' => $site?->geofence_radius_m === null ? null : (int) $site->geofence_radius_m,
                'supervisor_phone' => $site?->supervisor_phone,
                // The estate's boundary as its site plan draws it (M57.5 §4.5), and where it came from; null without one.
                'boundary' => ($points = $site?->boundary === null ? null : json_decode((string) $site->boundary, true)) !== null && count($points) >= 3
                    ? ['points' => array_values($points), 'source' => (string) ($site->boundary_source ?? '')]
                    : null,
            ],
            // M27 §4: the estate's phases, for each checkpoint's; and how long after one checkpoint the next is due.
            'phases' => $this->phasesOf($estate),
            'patrolInterval' => [
                'minutes' => self::patrolInterval($estate),
                'set' => $site?->patrol_interval_minutes !== null,
                'default' => PatrolCheckpoint::CADENCE_MINUTES,
                'grace' => PatrolCheckpoint::GRACE_MINUTES,
                'min' => self::MIN_PATROL_INTERVAL,
                'max' => self::MAX_PATROL_INTERVAL,
            ],
        ];
    }

    /** How long after one checkpoint the next is due on this estate's patrol (M27 §4.4; `PatrolCheckpoint::cadenceFor`). */
    public static function patrolInterval(string $estateId): int
    {
        return PatrolCheckpoint::cadenceFor($estateId);
    }

    /** Set the estate's patrol interval, with the reason; null puts the platform's own back (M27 §4.4). */
    public function setPatrolInterval(User $viewer, string $estateId, ?int $minutes, string $reason): int
    {
        if (! $this->mayPlace($viewer, $estateId)) {
            throw new DomainException('No client this role covers with that id.');
        }

        if ($minutes !== null && ($minutes < self::MIN_PATROL_INTERVAL || $minutes > self::MAX_PATROL_INTERVAL)) {
            throw new DomainException('The patrol interval is between '.self::MIN_PATROL_INTERVAL.' and '.self::MAX_PATROL_INTERVAL.' minutes: how long after one checkpoint the next is due.');
        }

        $reason = self::reasonOf($reason);
        $before = DB::connection('mysql')->table('estate_sites')->where('tenant_id', $estateId)->value('patrol_interval_minutes');

        if (($before === null ? null : (int) $before) === $minutes) {
            throw new DomainException('That is this estate\'s patrol interval already. Saving it again would record a change nobody made.');
        }

        DB::connection('mysql')->transaction(function () use ($estateId, $minutes, $before, $reason): void {
            DB::connection('mysql')->table('estate_sites')->updateOrInsert(
                ['tenant_id' => $estateId],
                ['patrol_interval_minutes' => $minutes, 'updated_at' => now(), 'created_at' => now()],
            );

            $this->audit->record(
                action: 'site.patrol_interval',
                entityType: 'EstateSite',
                entityId: $estateId,
                before: ['patrol_interval_minutes' => $before === null ? null : (int) $before],
                after: ['patrol_interval_minutes' => $minutes, 'reason' => $reason],
                tenantId: $estateId,
            );
        });

        return $minutes ?? PatrolCheckpoint::CADENCE_MINUTES;
    }

    /**
     * WHERE THE ESTATE'S PATROL STARTS (M30 §7.2 step 3; the client in M27: "their patrol would start at the main
     * gate"): one of its gates, or none. Audited with the gate before and after.
     */
    public function setPatrolStart(User $viewer, string $estateId, ?int $postId, string $reason): void
    {
        if (! $this->mayPlace($viewer, $estateId)) {
            throw new DomainException('No client this role covers with that id.');
        }

        $post = $postId === null ? null : Post::query()->whereKey($postId)->where('tenant_id', $estateId)->where('is_active', true)->first();

        if ($postId !== null && (! $post instanceof Post || $post->isPatrolRoute())) {
            throw new DomainException('A patrol starts at one of this estate\'s gates. Choose a gate.');
        }

        $reason = self::reasonOf($reason);
        $before = DB::connection('mysql')->table('estate_sites')->where('tenant_id', $estateId)->value('patrol_post_id');
        $before = $before === null ? null : (int) $before;

        if ($before === $postId) {
            throw new DomainException('That is where the patrol starts already.');
        }

        DB::connection('mysql')->transaction(function () use ($estateId, $postId, $post, $before, $reason): void {
            DB::connection('mysql')->table('estate_sites')->updateOrInsert(
                ['tenant_id' => $estateId],
                ['patrol_post_id' => $postId, 'updated_at' => now(), 'created_at' => now()],
            );

            $this->audit->record(
                action: 'site.patrol_start',
                entityType: 'EstateSite',
                entityId: $estateId,
                before: ['patrol_post_id' => $before],
                after: ['patrol_post_id' => $postId, 'post' => $post?->name, 'reason' => $reason],
                tenantId: $estateId,
            );
        });
    }

    /** The gate the estate's patrol starts at, or null (M30 §7.2 step 3). */
    public static function patrolStart(string $estateId): ?int
    {
        $id = DB::connection('mysql')->table('estate_sites')->where('tenant_id', $estateId)->value('patrol_post_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * THE ESTATE'S ROUTE IN A NEW ORDER, AT ONCE (M30 §7.2 step 3: "reordered by dragging in the list"). Every active
     * checkpoint on the estate's own route, each exactly once, in the order given; numbered again from one, which is the
     * order the Guard App's tour reads (`PatrolController::checkpoints`, by sequence). The codes never change, so the
     * tags already on walls still scan; a tag printed before shows its old number until it is printed again.
     *
     * @param  list<int|string>  $order  checkpoint ids, as a form sends them
     */
    public function reorderRoute(User $viewer, string $estateId, array $order): void
    {
        if (! $this->mayPlace($viewer, $estateId)) {
            throw new DomainException('No client this role covers with that id.');
        }

        $route = PatrolCheckpoint::query()->where('tenant_id', $estateId)->whereNull('post_id')->where('is_active', true)->get()->keyBy('id');
        $order = array_map('intval', $order);

        if (count($order) !== $route->count() || count(array_unique($order)) !== count($order) || array_diff($order, $route->keys()->all()) !== []) {
            throw new DomainException('The route changed since it was shown. Nothing was moved: look at it again.');
        }

        $before = $route->sortBy([['sequence', 'asc'], ['id', 'asc']])->keys()->values()->all();

        if ($before === $order) {
            throw new DomainException('That is the route\'s order already.');
        }

        DB::connection('mysql')->transaction(function () use ($route, $order, $before, $estateId): void {
            foreach ($order as $i => $id) {
                $stop = $route->get($id);
                if ($stop instanceof PatrolCheckpoint && (int) $stop->sequence !== $i + 1) {
                    $stop->forceFill(['sequence' => $i + 1])->save();
                }
            }

            $this->audit->record(
                action: 'site.route_reordered',
                entityType: 'EstateSite',
                entityId: $estateId,
                before: ['order' => $before],
                after: ['order' => $order],
                tenantId: $estateId,
            );
        });
    }

    /**
     * A new post at an estate, placed on the map as it is made (M12 §1.1: "the estate and each of its posts — main
     * gate, back gate, patrol route start"). A post is only ever made here, with its position and its reason.
     *
     * A gate or a relief, with the other names the estate uses for it (M26). Not a patrol route (M27): a patrol is a
     * shift's duty at the gate it starts from.
     */
    public function addPost(User $viewer, string $estateId, string $name, string $type, float $lat, float $lng, int $radiusM, string $reason, ?string $alsoCalled = null): Post
    {
        if (! $this->mayPlace($viewer, $estateId)) {
            throw new DomainException('No client this role covers with that id.');
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('A post has a name the roster can show — "Main Gate", "Back Gate" — of at most 120 characters.');
        }

        if (! array_key_exists($type, self::NEW_POST_TYPES)) {
            throw new DomainException('A post is a gate or a relief. A patrol is not a post: post a patrol shift at the gate it starts from, and its officer clocks in there.');
        }

        if (Post::query()->where('tenant_id', $estateId)->where('is_active', true)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new DomainException('This estate already has a post called '.$name.'. Place that one instead, or give this one another name.');
        }

        $also = self::alsoCalledOf($alsoCalled, $name);
        self::assertPosition($lat, $lng);
        self::assertRadius($radiusM);
        $reason = self::reasonOf($reason);

        return DB::connection('mysql')->transaction(function () use ($estateId, $name, $type, $lat, $lng, $radiusM, $reason, $also): Post {
            $post = Post::query()->create([
                'tenant_id' => $estateId,
                'name' => $name,
                'also_called' => $also,
                'type' => $type,
                'is_active' => true,
                'latitude' => round($lat, 7),
                'longitude' => round($lng, 7),
                'geofence_radius_m' => $radiusM,
            ]);

            $this->audit->record(
                action: 'site.post_added',
                entityType: 'Post',
                entityId: (string) $post->id,
                after: ['name' => $name, 'also_called' => $also, 'type' => $type, 'latitude' => $post->latitude, 'longitude' => $post->longitude, 'geofence_radius_m' => $radiusM, 'reason' => $reason],
                tenantId: $estateId,
            );

            return $post;
        });
    }

    /**
     * THE OTHER NAMES THE ESTATE USES FOR A POST (M26): "Gate 5, Phase 5 Gate". Shown beside the name on every screen
     * that names the post, so an officer who knows only the local name recognises it. Empty takes them away.
     */
    public function setAlsoCalled(User $viewer, int $postId, ?string $alsoCalled, string $reason): Post
    {
        $post = $this->activePost($viewer, $postId);
        $also = self::alsoCalledOf($alsoCalled, (string) $post->name);
        $reason = self::reasonOf($reason);

        if ($also === $post->also_called) {
            throw new DomainException('That is what '.$post->name.' is already also called. Saving it again would record a change nobody made.');
        }

        $before = $post->also_called;

        return DB::connection('mysql')->transaction(function () use ($post, $also, $before, $reason): Post {
            $post->forceFill(['also_called' => $also])->save();

            $this->audit->record(
                action: 'site.post_also_called',
                entityType: 'Post',
                entityId: (string) $post->id,
                before: ['also_called' => $before],
                after: ['also_called' => $also, 'reason' => $reason],
                tenantId: (string) $post->tenant_id,
            );

            return $post;
        });
    }

    /**
     * A POST'S OWN NAME, CHANGED (M30 §7.2, "Rename"): the same post, so everything recorded at it reads under the new
     * name, as everything recorded at it already reads under whatever its name is. Held to the rule a new post is: a
     * name of at most 120 characters that no other post at the estate has.
     */
    public function renamePost(User $viewer, int $postId, string $name, string $reason): Post
    {
        $post = $this->activePost($viewer, $postId);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('A gate has a name the schedule can show, like "Main Gate" or "Back Gate", of at most 120 characters.');
        }

        if ($name === $post->name) {
            throw new DomainException('That is '.$post->name.'\'s name already. Saving it again would record a change nobody made.');
        }

        if (Post::query()->where('tenant_id', $post->tenant_id)->where('is_active', true)->whereKeyNot($post->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new DomainException('This estate already has a post called '.$name.'. Give this one another name.');
        }

        $reason = self::reasonOf($reason);
        $before = (string) $post->name;

        return DB::connection('mysql')->transaction(function () use ($post, $name, $before, $reason): Post {
            $post->forceFill(['name' => $name])->save();

            $this->audit->record(
                action: 'site.post_renamed',
                entityType: 'Post',
                entityId: (string) $post->id,
                before: ['name' => $before],
                after: ['name' => $name, 'reason' => $reason],
                tenantId: (string) $post->tenant_id,
            );

            return $post;
        });
    }

    /**
     * A POST THAT DOES NOT EXIST, TAKEN AWAY (M26: "We don't have a third gate anywhere"). Deleted when nothing was ever
     * done at it: no shift worked, no gate entry, no checkpoint, no standing orders. Otherwise RETIRED, because that
     * history is a record and is never deleted: inactive, gone from the roster, the map and every list, its records
     * still reading under its name. Either way its un-started shifts go (they rostered officers to a gate that is not
     * there), and its officers keep their client and lose the post. Its checkpoints, if it was an older patrol route,
     * become the estate's own route rather than vanishing with it: their tags may already be on walls.
     *
     * @return array{post: string, outcome: 'deleted'|'retired', shifts_removed: int, officers_unposted: int, checkpoints_kept: int}
     */
    public function removePost(User $viewer, int $postId, string $reason): array
    {
        return $this->remove($this->activePost($viewer, $postId), self::reasonOf($reason));
    }

    /**
     * `removePost()` itself, for a caller that has already decided which post, and why: the console above, and the
     * command that corrects an estate's posts to what the client says it has (`phoenix:two-gates`).
     *
     * @return array{post: string, outcome: 'deleted'|'retired', shifts_removed: int, officers_unposted: int, checkpoints_kept: int}
     */
    public function remove(Post $post, string $reason): array
    {
        return DB::connection('mysql')->transaction(function () use ($post, $reason): array {
            $id = (int) $post->id;
            $db = DB::connection('mysql');

            $unstarted = $db->table('shifts')->where('post_id', $id)->whereNull('actual_start');
            $shiftsRemoved = (clone $unstarted)->count();
            $unstarted->delete();

            $officers = $db->table('guards')->where('post_id', $id)->update(['post_id' => null, 'updated_at' => now()]);

            // An older patrol route's stops join the estate's route, after its own, in their order.
            $next = (int) $db->table('patrol_checkpoints')->where('tenant_id', $post->tenant_id)->whereNull('post_id')->where('is_active', true)->max('sequence');
            $kept = 0;
            foreach ($db->table('patrol_checkpoints')->where('post_id', $id)->where('is_active', true)->orderBy('sequence')->orderBy('id')->pluck('id') as $checkpointId) {
                $db->table('patrol_checkpoints')->where('id', $checkpointId)->update(['post_id' => null, 'sequence' => $next + (++$kept), 'updated_at' => now()]);
            }

            $history = $db->table('shifts')->where('post_id', $id)->exists()
                || $db->table('gate_events')->where('post_id', $id)->exists()
                || $db->table('patrol_checkpoints')->where('post_id', $id)->exists()
                || $db->table('standing_order_sets')->where('post_id', $id)->exists();

            if ($history) {
                $post->forceFill(['is_active' => false])->save();
            } else {
                $post->delete();
            }

            $outcome = $history ? 'retired' : 'deleted';

            $this->audit->record(
                action: 'site.post_removed',
                entityType: 'Post',
                entityId: (string) $id,
                before: ['name' => $post->name, 'type' => $post->type, 'latitude' => $post->latitude, 'longitude' => $post->longitude],
                after: ['outcome' => $outcome, 'shifts_removed' => $shiftsRemoved, 'officers_unposted' => $officers, 'checkpoints_kept' => $kept, 'reason' => $reason],
                tenantId: (string) $post->tenant_id,
            );

            return ['post' => (string) $post->name, 'outcome' => $outcome, 'shifts_removed' => $shiftsRemoved, 'officers_unposted' => $officers, 'checkpoints_kept' => $kept];
        });
    }

    /**
     * What `remove()` would do to this post, changing nothing: for the command that says so before it is told to go
     * ahead (`estate:remove-post`). The same rules in the same order: the un-started shifts and the live checkpoints
     * leave first, and the post is retired only if a record is still left at it after that.
     *
     * @return array{post: string, outcome: 'deleted'|'retired', shifts_removed: int, officers_unposted: int, checkpoints_kept: int}
     */
    public function removalPreview(Post $post): array
    {
        $db = DB::connection('mysql');
        $id = (int) $post->id;
        $kept = $db->table('patrol_checkpoints')->where('post_id', $id)->where('is_active', true)->count();

        $history = $db->table('shifts')->where('post_id', $id)->whereNotNull('actual_start')->exists()
            || $db->table('gate_events')->where('post_id', $id)->exists()
            || $db->table('patrol_checkpoints')->where('post_id', $id)->count() > $kept
            || $db->table('standing_order_sets')->where('post_id', $id)->exists();

        return [
            'post' => (string) $post->name,
            'outcome' => $history ? 'retired' : 'deleted',
            'shifts_removed' => $db->table('shifts')->where('post_id', $id)->whereNull('actual_start')->count(),
            'officers_unposted' => $db->table('guards')->where('post_id', $id)->count(),
            'checkpoints_kept' => $kept,
        ];
    }

    /**
     * WHERE THE ESTATE ITSELF IS, and who a guard at its gate calls (M12 §1.1 step 3; §1.3). The estate's fence is
     * what a clock-in is judged against when the rostered post has no position of its own — the audit entry of that
     * clock-in says so — and it is set, moved or taken off here, with the reason. Both halves may be set alone.
     *
     * @param  array{lat: float|null, lng: float|null, radius_m: int|null, supervisor_phone: string|null}  $site
     * @param  string|null  $phoneExample  the estate's own example for a refused number (M32 F7, Places); the classic
     *                                     Sites page, frozen for the client's comparison, keeps its own words
     */
    public function placeEstate(User $viewer, string $estateId, array $site, string $reason, ?string $phoneExample = null): void
    {
        if (! $this->mayPlace($viewer, $estateId)) {
            throw new DomainException('No client this role covers with that id.');
        }

        $lat = $site['lat'];
        $lng = $site['lng'];
        $radius = $site['radius_m'];

        if (($lat === null) !== ($lng === null) || ($lat === null) !== ($radius === null)) {
            throw new DomainException('The estate is placed with its centre and its radius together, or taken off the map with neither.');
        }

        if ($lat !== null && $lng !== null && $radius !== null) {
            self::assertPosition($lat, $lng);
            self::assertRadius($radius);
        }

        $phone = self::phoneOf($site['supervisor_phone'], $phoneExample);
        $reason = self::reasonOf($reason);
        $row = DB::connection('mysql')->table('estate_sites')->where('tenant_id', $estateId)->first();
        $before = [
            'latitude' => $row?->latitude === null ? null : (float) $row->latitude,
            'longitude' => $row?->longitude === null ? null : (float) $row->longitude,
            'geofence_radius_m' => $row?->geofence_radius_m === null ? null : (int) $row->geofence_radius_m,
            'supervisor_phone' => $row?->supervisor_phone,
        ];
        $after = [
            'latitude' => $lat === null ? null : round($lat, 7),
            'longitude' => $lng === null ? null : round($lng, 7),
            'geofence_radius_m' => $radius,
            'supervisor_phone' => $phone,
        ];

        if ($before == $after) {
            throw new DomainException('That is how this estate is placed already. Saving it again would record a change nobody made.');
        }

        DB::connection('mysql')->transaction(function () use ($estateId, $before, $after, $reason): void {
            DB::connection('mysql')->table('estate_sites')->updateOrInsert(
                ['tenant_id' => $estateId],
                [...$after, 'updated_at' => now(), 'created_at' => now()],
            );

            $this->audit->record(
                action: 'site.estate_placed',
                entityType: 'EstateSite',
                entityId: $estateId,
                before: $before,
                after: [...$after, 'reason' => $reason],
                tenantId: $estateId,
            );
        });
    }

    /**
     * WHETHER THIS CLIENT'S OWN CONSOLE SEES ITS GUARDS ON THE MAP (M11 §3.3; QUESTIONS TRACK-01). A guard's position
     * belongs to the security company, so the estate sees "guards on site: N" and no pins — unless its contract says
     * otherwise. That is the client's policy decision, so it is a per-contract setting, off until somebody here turns
     * it on, and every change is audited.
     */
    public function setEstateSeesGuards(User $viewer, string $estateId, bool $sees): void
    {
        if (! $this->mayPlace($viewer, $estateId)) {
            throw new DomainException('No client this role covers with that id.');
        }

        $before = DB::connection('mysql')->table('subscriptions')->where('tenant_id', $estateId)->value('estate_sees_guard_positions');

        if ($before === null) {
            throw new DomainException('This client has no contract yet. Set its plan first; the setting belongs to the contract.');
        }

        DB::connection('mysql')->transaction(function () use ($estateId, $sees, $before): void {
            DB::connection('mysql')->table('subscriptions')->where('tenant_id', $estateId)->update(['estate_sees_guard_positions' => $sees, 'updated_at' => now()]);

            $this->audit->record(
                action: $sees ? 'site.guard_positions_shared_with_estate' : 'site.guard_positions_withdrawn_from_estate',
                entityType: 'Subscription',
                entityId: $estateId,
                before: ['estate_sees_guard_positions' => (bool) $before],
                after: ['estate_sees_guard_positions' => $sees],
                tenantId: $estateId,
            );
        });
    }

    /** Place a post, and set how close a guard must be to clock in there — with the reason, for the audit entry. */
    public function placePost(User $viewer, int $postId, float $lat, float $lng, int $radiusM, string $reason): void
    {
        self::assertPosition($lat, $lng);
        self::assertRadius($radiusM);
        $reason = self::reasonOf($reason);

        $post = Post::query()->whereKey($postId)->first();

        if (! $post instanceof Post || ! $this->mayPlace($viewer, (string) $post->tenant_id)) {
            throw new DomainException('No post of a client this role covers with that id.');
        }

        if ($post->isPatrolRoute()) {
            throw new DomainException($post->name.' is a patrol route, and a patrol covers more ground than any circle. It is not placed: choose the gate it starts from, and its officer clocks in there.');
        }

        $before = ['latitude' => $post->latitude, 'longitude' => $post->longitude, 'geofence_radius_m' => $post->geofence_radius_m];

        DB::connection('mysql')->transaction(function () use ($post, $lat, $lng, $radiusM, $before, $reason): void {
            $post->forceFill(['latitude' => round($lat, 7), 'longitude' => round($lng, 7), 'geofence_radius_m' => $radiusM])->save();

            $this->audit->record(
                action: 'site.post_placed',
                entityType: 'Post',
                entityId: (string) $post->id,
                before: $before,
                after: ['latitude' => $post->latitude, 'longitude' => $post->longitude, 'geofence_radius_m' => $radiusM, 'reason' => $reason],
                tenantId: (string) $post->tenant_id,
            );
        });
    }

    /** Place a checkpoint where it stands, or take it off the map (null, null). */
    public function placeCheckpoint(User $viewer, int $checkpointId, ?float $lat, ?float $lng): void
    {
        if (($lat === null) !== ($lng === null)) {
            throw new DomainException('A checkpoint is placed with both its latitude and its longitude, or taken off the map with neither.');
        }

        if ($lat !== null && $lng !== null) {
            self::assertPosition($lat, $lng);
        }

        $row = DB::connection('mysql')->table('patrol_checkpoints')->where('id', $checkpointId)->first(['id', 'tenant_id', 'latitude', 'longitude']);

        if ($row === null || ! $this->mayPlace($viewer, (string) $row->tenant_id)) {
            throw new DomainException('No checkpoint of a client this role covers with that id.');
        }

        DB::connection('mysql')->transaction(function () use ($row, $lat, $lng): void {
            DB::connection('mysql')->table('patrol_checkpoints')->where('id', $row->id)->update([
                'latitude' => $lat === null ? null : round($lat, 7),
                'longitude' => $lng === null ? null : round($lng, 7),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                action: $lat === null ? 'site.checkpoint_unplaced' : 'site.checkpoint_placed',
                entityType: 'PatrolCheckpoint',
                entityId: (string) $row->id,
                before: ['latitude' => $row->latitude, 'longitude' => $row->longitude],
                after: ['latitude' => $lat, 'longitude' => $lng],
                tenantId: (string) $row->tenant_id,
            );
        });
    }

    /**
     * A NEW CHECKPOINT ON A PATROL ROUTE (M21 §1.1). Before this there was no way to make one: checkpoints came from a
     * seeder, and a patrol system whose office cannot make a tag is not finished. A checkpoint belongs to a patrol
     * route (a post of kind Patrol route), is placed as it is made, takes the next place on the route, and is given a
     * code nobody could guess — the code its printed tag carries, and the proof a guard stood there. Audited with the
     * reason, as every placement here is; the code itself is never written to the audit log.
     */
    public function addCheckpoint(User $viewer, int $postId, string $label, float $lat, float $lng, int $radiusM, string $reason): PatrolCheckpoint
    {
        $post = Post::query()->whereKey($postId)->where('is_active', true)->first();

        if (! $post instanceof Post || ! $this->mayPlace($viewer, (string) $post->tenant_id)) {
            throw new DomainException('No post of a client this role covers with that id.');
        }

        if ($post->type !== 'patrol') {
            throw new DomainException($post->name.' is a '.mb_strtolower(self::POST_TYPES[$post->type] ?? $post->type).', not a patrol route. Checkpoints are the stops on a patrol route: add them to one, or add a post of kind Patrol route first.');
        }

        $label = trim($label);

        if ($label === '' || mb_strlen($label) > 120) {
            throw new DomainException('A checkpoint has a name a guard can find — "North fence", "Pool gate" — of at most 120 characters.');
        }

        if (PatrolCheckpoint::query()->where('post_id', $post->id)->where('is_active', true)->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])->exists()) {
            throw new DomainException($post->name.' already has a checkpoint called '.$label.'. Give this one another name, or print that one\'s tag again.');
        }

        self::assertPosition($lat, $lng);

        if ($radiusM < self::MIN_RADIUS_M || $radiusM > self::MAX_CHECKPOINT_RADIUS_M) {
            throw new DomainException('A checkpoint\'s radius is between '.self::MIN_RADIUS_M.' and '.self::MAX_CHECKPOINT_RADIUS_M.' metres: how close a guard\'s phone must be to the tag for the scan to count.');
        }

        $reason = self::reasonOf($reason);

        return DB::connection('mysql')->transaction(fn (): PatrolCheckpoint => $this->createCheckpoint((string) $post->tenant_id, $post, $label, null, $lat, $lng, $radiusM, $reason));
    }

    /**
     * A CHECKPOINT ON THE ESTATE'S OWN PATROL ROUTE (M27 §4.1): the route a patrol shift tours, whichever gate it starts
     * from. Made with its phase, and with or without a position: the tag can be printed and mounted first and the
     * checkpoint placed afterwards from where it actually hangs. A scan of a checkpoint not yet placed is recorded and
     * said to be unchecked, never refused; placing it is the Director's alone.
     */
    public function addRouteCheckpoint(User $viewer, string $estateId, string $label, ?string $phase, ?float $lat, ?float $lng, int $radiusM, string $reason): PatrolCheckpoint
    {
        if (! $this->mayPlace($viewer, $estateId)) {
            throw new DomainException('No client this role covers with that id.');
        }

        $label = self::checkpointLabelOf($label);
        $phase = $this->phaseOf($estateId, $phase);
        self::assertRouteNameFree($estateId, $label);

        if (($lat === null) !== ($lng === null)) {
            throw new DomainException('A checkpoint is placed with both its latitude and its longitude, or made with neither and placed later.');
        }

        if ($lat !== null && $lng !== null) {
            self::assertPosition($lat, $lng);
        }

        self::assertCheckpointRadius($radiusM);
        $reason = self::reasonOf($reason);

        return DB::connection('mysql')->transaction(fn (): PatrolCheckpoint => $this->createCheckpoint($estateId, null, $label, $phase, $lat, $lng, $radiusM, $reason));
    }

    /**
     * MANY CHECKPOINTS AT ONCE, BY NAME (M27 §4.1): fifteen around five phases is one list, typed or pasted, one per line,
     * each optionally led by its phase — "Phase 2: Pool deck", "Basketball court". They go on the estate's route in the
     * order written, with no position: their tags are printed together, mounted, and the checkpoints placed from where
     * they hang. All of the list or none of it, so a mistake on line 9 leaves nothing half made.
     *
     * @return list<PatrolCheckpoint>
     */
    public function addRouteCheckpoints(User $viewer, string $estateId, string $lines, string $reason): array
    {
        if (! $this->mayPlace($viewer, $estateId)) {
            throw new DomainException('No client this role covers with that id.');
        }

        $phases = $this->phasesOf($estateId);
        $wanted = [];
        $seen = [];

        foreach (preg_split('/\R/u', $lines) ?: [] as $i => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $phase = null;

            foreach ($phases as $known) {
                if (preg_match('/^'.preg_quote($known, '/').'\s*[:\-–—]\s*(.+)$/iu', $line, $m) === 1) {
                    [$phase, $line] = [$known, trim($m[1])];
                    break;
                }
            }

            try {
                $label = self::checkpointLabelOf($line);
                self::assertRouteNameFree($estateId, $label);
            } catch (DomainException $refused) {
                throw new DomainException('Line '.($i + 1).': '.$refused->getMessage());
            }

            if (isset($seen[mb_strtolower($label)])) {
                throw new DomainException('Line '.($i + 1).': '.$label.' is in the list twice. Each checkpoint has a name of its own, so a guard can tell them apart at night.');
            }

            $seen[mb_strtolower($label)] = true;
            $wanted[] = ['label' => $label, 'phase' => $phase];
        }

        if ($wanted === []) {
            throw new DomainException('Write the checkpoints one to a line: "Phase 1: Basketball court", or just the name.');
        }

        if (count($wanted) > self::MAX_CHECKPOINTS_AT_ONCE) {
            throw new DomainException('That is '.count($wanted).' checkpoints. Add at most '.self::MAX_CHECKPOINTS_AT_ONCE.' at once.');
        }

        $reason = self::reasonOf($reason);

        return DB::connection('mysql')->transaction(fn (): array => array_map(
            fn (array $w): PatrolCheckpoint => $this->createCheckpoint($estateId, null, $w['label'], $w['phase'], null, null, PatrolCheckpoint::DEFAULT_RADIUS_M, $reason),
            $wanted,
        ));
    }

    /**
     * MANY CHECKPOINTS PLACED AT ONCE (M27 §4.3, the coordinate route). The installer standing at each mounted tag sends
     * its name and the two numbers, as GATE_POSITIONS.md says; the Director pastes the messages, the page matches each
     * to its checkpoint and shows the list, and only what the Director confirms is saved. A guard never places a
     * checkpoint: a position is a decision about where proof of presence is, and it is taken here, by name, audited
     * one by one with the reason.
     *
     * @param  list<array{id: int, lat: float, lng: float}>  $places
     */
    public function placeRouteCheckpoints(User $viewer, string $estateId, array $places, string $reason): int
    {
        if (! $this->mayPlace($viewer, $estateId)) {
            throw new DomainException('No client this role covers with that id.');
        }

        if ($places === [] || count($places) > self::MAX_CHECKPOINTS_AT_ONCE) {
            throw new DomainException('Place between one and '.self::MAX_CHECKPOINTS_AT_ONCE.' checkpoints at once.');
        }

        $ids = array_map(static fn (array $p): int => (int) $p['id'], $places);

        if (count(array_unique($ids)) !== count($ids)) {
            throw new DomainException('A checkpoint is in the list twice. Each one is placed once, where its tag hangs.');
        }

        $rows = PatrolCheckpoint::query()->whereIn('id', $ids)->where('tenant_id', $estateId)->where('is_active', true)->get()->keyBy('id');

        if ($rows->count() !== count($ids)) {
            throw new DomainException('One of those is not a checkpoint of this estate any more. Paste the messages again.');
        }

        foreach ($places as $p) {
            self::assertPosition((float) $p['lat'], (float) $p['lng']);
        }

        $reason = self::reasonOf($reason);

        DB::connection('mysql')->transaction(function () use ($places, $rows, $reason, $estateId): void {
            foreach ($places as $p) {
                $row = $rows->get((int) $p['id']);
                $before = ['latitude' => $row->latitude, 'longitude' => $row->longitude];

                $row->forceFill(['latitude' => round((float) $p['lat'], 7), 'longitude' => round((float) $p['lng'], 7)])->save();

                $this->audit->record(
                    action: 'site.checkpoint_placed',
                    entityType: 'PatrolCheckpoint',
                    entityId: (string) $row->id,
                    before: $before,
                    after: ['label' => $row->label, 'latitude' => $row->latitude, 'longitude' => $row->longitude, 'reason' => $reason],
                    tenantId: $estateId,
                );
            }
        });

        return count($places);
    }

    /**
     * A CHECKPOINT ONE PLACE EARLIER OR LATER ON ITS ROUTE (M27 §4.4): the order is the tour's, and "4 of 15" on a tag
     * and on the board is this sequence. Swapped with its neighbour; the tags printed before show the old numbers, so
     * the answer says to print again if the numbers matter on the wall. The codes never change.
     */
    public function moveCheckpoint(User $viewer, int $checkpointId, string $direction): PatrolCheckpoint
    {
        $checkpoint = PatrolCheckpoint::query()->whereKey($checkpointId)->where('is_active', true)->first();

        if (! $checkpoint instanceof PatrolCheckpoint || ! $this->mayPlace($viewer, (string) $checkpoint->tenant_id)) {
            throw new DomainException('No checkpoint of a client this role covers with that id.');
        }

        if (! in_array($direction, ['up', 'down'], true)) {
            throw new DomainException('A checkpoint moves up the route or down it.');
        }

        $route = PatrolCheckpoint::query()->where('tenant_id', $checkpoint->tenant_id)->where('post_id', $checkpoint->post_id)
            ->where('is_active', true)->orderBy('sequence')->orderBy('id')->get()->values();
        $at = $route->search(static fn (PatrolCheckpoint $c): bool => (int) $c->id === (int) $checkpoint->id);
        $to = $direction === 'up' ? $at - 1 : $at + 1;

        if ($at === false || $to < 0 || $to >= $route->count()) {
            throw new DomainException($checkpoint->label.' is already '.($direction === 'up' ? 'first' : 'last').' on the route.');
        }

        return DB::connection('mysql')->transaction(function () use ($route, $at, $to, $checkpoint): PatrolCheckpoint {
            $order = $route->all();
            [$order[$at], $order[$to]] = [$order[$to], $order[$at]];

            foreach (array_values($order) as $i => $stop) {
                if ((int) $stop->sequence !== $i + 1) {
                    $stop->forceFill(['sequence' => $i + 1])->save();
                }
            }

            $this->audit->record(
                action: 'site.checkpoint_moved',
                entityType: 'PatrolCheckpoint',
                entityId: (string) $checkpoint->id,
                before: ['sequence' => $at + 1],
                after: ['label' => $checkpoint->label, 'sequence' => $to + 1, 'of' => count($order)],
                tenantId: (string) $checkpoint->tenant_id,
            );

            return $checkpoint->refresh();
        });
    }

    /** One checkpoint, its code and its place, on a route: the estate's own (no post) or an older post's. */
    private function createCheckpoint(string $estateId, ?Post $post, string $label, ?string $phase, ?float $lat, ?float $lng, int $radiusM, string $reason): PatrolCheckpoint
    {
        $sequence = (int) PatrolCheckpoint::query()->where('tenant_id', $estateId)->where('post_id', $post?->id)->where('is_active', true)->max('sequence') + 1;

        $checkpoint = PatrolCheckpoint::query()->create([
            'tenant_id' => $estateId,
            'post_id' => $post?->id,
            'label' => $label,
            'phase' => $phase,
            'code' => PatrolCheckpoint::newCode(),
            'sequence' => $sequence,
            'is_active' => true,
            'latitude' => $lat === null ? null : round($lat, 7),
            'longitude' => $lng === null ? null : round($lng, 7),
            'radius_m' => $radiusM,
        ]);

        $this->audit->record(
            action: 'site.checkpoint_added',
            entityType: 'PatrolCheckpoint',
            entityId: (string) $checkpoint->id,
            after: ['label' => $label, 'post' => $post?->name, 'phase' => $phase, 'sequence' => $sequence, 'latitude' => $checkpoint->latitude, 'longitude' => $checkpoint->longitude, 'radius_m' => $radiusM, 'reason' => $reason],
            tenantId: $estateId,
        );

        return $checkpoint;
    }

    /** A checkpoint's name: a landmark a guard finds at 2 AM, "Basketball court", of at most 120 characters. */
    private static function checkpointLabelOf(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');

        if ($label === '' || mb_strlen($label) > 120) {
            throw new DomainException('A checkpoint has a name a guard can find at night — a landmark like "Basketball court", "Pool gate" — of at most 120 characters.');
        }

        return $label;
    }

    /** No two checkpoints on the estate's route share a name: a guard told "scan the Pool gate" must know which. */
    private static function assertRouteNameFree(string $estateId, string $label): void
    {
        if (PatrolCheckpoint::query()->where('tenant_id', $estateId)->whereNull('post_id')->where('is_active', true)->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])->exists()) {
            throw new DomainException('The estate\'s route already has a checkpoint called '.$label.'. Give this one another name, or print that one\'s tag again.');
        }
    }

    private static function assertCheckpointRadius(int $radiusM): void
    {
        if ($radiusM < self::MIN_RADIUS_M || $radiusM > self::MAX_CHECKPOINT_RADIUS_M) {
            throw new DomainException('A checkpoint\'s radius is between '.self::MIN_RADIUS_M.' and '.self::MAX_CHECKPOINT_RADIUS_M.' metres: how close a guard\'s phone must be to the tag for the scan to count.');
        }
    }

    /**
     * A CHECKPOINT TAKEN OFF ITS ROUTE (M22 §4). A checkpoint could be made and never unmade: one placed in error, or a
     * stop the route no longer has, stayed in every guard's tour, and every round was "missed" there for ever. Retired,
     * it is inactive — out of the tour, off the map, off the alertness board, its tag refused at the next scan — and
     * its scans stay on the record under its name. The route's other checkpoints close up behind it, so the tour reads
     * "2 of 2" rather than "3 of 2"; a tag already printed keeps the place it was printed with, and printing it again
     * gives the new one. Audited with the reason. There is no un-retire: a stop that comes back is added again, with a
     * new code, because the old tag may still be on a wall somewhere.
     */
    public function retireCheckpoint(User $viewer, int $checkpointId, string $reason): PatrolCheckpoint
    {
        $checkpoint = PatrolCheckpoint::query()->with('post')->whereKey($checkpointId)->where('is_active', true)->first();

        if (! $checkpoint instanceof PatrolCheckpoint || ! $this->mayPlace($viewer, (string) $checkpoint->tenant_id)) {
            throw new DomainException('No checkpoint of a client this role covers with that id.');
        }

        $reason = self::reasonOf($reason);

        return DB::connection('mysql')->transaction(function () use ($checkpoint, $reason): PatrolCheckpoint {
            $checkpoint->forceFill(['is_active' => false])->save();

            // The stops left on the route, in their order, numbered again from one.
            // The same estate's route: the estate's own has no post, and every estate's own would otherwise be one list.
            $left = PatrolCheckpoint::query()->where('tenant_id', $checkpoint->tenant_id)->where('post_id', $checkpoint->post_id)->where('is_active', true)->orderBy('sequence')->orderBy('id')->get();
            foreach ($left->values() as $i => $stop) {
                if ((int) $stop->sequence !== $i + 1) {
                    $stop->forceFill(['sequence' => $i + 1])->save();
                }
            }

            $this->audit->record(
                action: 'site.checkpoint_retired',
                entityType: 'PatrolCheckpoint',
                entityId: (string) $checkpoint->id,
                before: ['label' => $checkpoint->label, 'sequence' => $checkpoint->sequence],
                after: ['post' => $checkpoint->post?->name, 'left_on_route' => $left->count(), 'reason' => $reason],
                tenantId: (string) $checkpoint->tenant_id,
            );

            return $checkpoint;
        });
    }

    /**
     * WHAT A CHECKPOINT'S PRINTED TAG CARRIES (M21 §1.1): the QR of its code, which is all the Guard App reads; the
     * checkpoint's name and its place on the route, so it is fixed on the right wall; the code again in letters, so a
     * damaged tag can be matched to its row. Printing again gives the same code: a tag that tears is replaced, not
     * re-keyed, and every scan of it before and after is the same checkpoint's. Null outside the viewer's scope.
     *
     * @return array<string, mixed>|null
     */
    public function tag(User $viewer, int $checkpointId): ?array
    {
        $checkpoint = PatrolCheckpoint::query()->with('post')->whereKey($checkpointId)->where('is_active', true)->first();

        if (! $checkpoint instanceof PatrolCheckpoint || ! $this->mayPlace($viewer, (string) $checkpoint->tenant_id)) {
            return null;
        }

        $onRoute = PatrolCheckpoint::query()->where('tenant_id', $checkpoint->tenant_id)->where('post_id', $checkpoint->post_id)->where('is_active', true)->count();
        $estate = Tenant::query()->find($checkpoint->tenant_id);

        $this->audit->record(
            action: 'site.checkpoint_tag_printed',
            entityType: 'PatrolCheckpoint',
            entityId: (string) $checkpoint->id,
            after: ['label' => $checkpoint->label],
            tenantId: (string) $checkpoint->tenant_id,
        );

        return [
            'title' => 'Checkpoint tag — '.$checkpoint->label,
            ...$this->tagOf($checkpoint, $onRoute, (string) ($estate->name ?? $checkpoint->tenant_id), $viewer),
        ];
    }

    /**
     * EVERY TAG ON THE ESTATE'S PATROL, TO PRINT AT ONCE (M26 §3.5, M27 §4.2). Fifteen tags around five phases are one
     * print, not fifteen: one page per tag, in route order, each exactly the tag `tag()` gives — its QR, its name, its
     * phase and "N of 15". Printing it again prints the same codes, so it is also the spare set kept at the gate house.
     * The estate's own route first, then any older route hanging from a post. Null outside the viewer's scope, or with
     * no active checkpoint.
     *
     * @return array{title: string, estateName: string, route: string, count: int, unplaced: int, tags: list<array<string, mixed>>}|null
     */
    public function routeTags(User $viewer, string $estateId): ?array
    {
        if (! $this->mayPlace($viewer, $estateId)) {
            return null;
        }

        $checkpoints = PatrolCheckpoint::query()->with('post')->where('tenant_id', $estateId)->where('is_active', true)
            ->orderByRaw('post_id IS NOT NULL')->orderBy('post_id')->orderBy('sequence')->orderBy('id')->get();

        if ($checkpoints->isEmpty()) {
            return null;
        }

        $estate = (string) (Tenant::query()->find($estateId)->name ?? $estateId);
        $onRoute = $checkpoints->countBy(static fn (PatrolCheckpoint $c): string => (string) ($c->post_id ?? 'estate'));

        $this->audit->record(
            action: 'site.route_tags_printed',
            entityType: 'EstateSite',
            entityId: $estateId,
            after: ['tags' => $checkpoints->count()],
            tenantId: $estateId,
        );

        return [
            'title' => 'Checkpoint tags — '.$estate,
            'estateName' => $estate,
            'route' => 'the patrol route',
            'count' => $checkpoints->count(),
            'unplaced' => $checkpoints->filter(static fn (PatrolCheckpoint $c): bool => $c->latitude === null || $c->longitude === null)->count(),
            'tags' => $checkpoints->map(fn (PatrolCheckpoint $c): array => $this->tagOf($c, (int) $onRoute->get((string) ($c->post_id ?? 'estate'), 0), $estate, $viewer))->values()->all(),
        ];
    }

    /**
     * What one tag carries (M21 §1.1): the QR of its code, which is all the Guard App reads; the checkpoint's name and
     * its place on the route; the code again in letters, so a damaged tag can be matched to its row.
     *
     * @return array<string, mixed>
     */
    private function tagOf(PatrolCheckpoint $checkpoint, int $onRoute, string $estateName, User $viewer): array
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(600, 2), new SvgImageBackEnd)))->writeString((string) $checkpoint->code);

        return [
            'estateName' => $estateName,
            // The estate's own route has no post; an older route is named by its post.
            'route' => $checkpoint->post?->label() ?? 'Patrol route',
            'label' => (string) $checkpoint->label,
            // "Phase 2 · Checkpoint 4 of 15" (M27 §4.2): the phase tells the installer which part of the estate it goes in.
            'place' => $onRoute > 0 ? trim(($checkpoint->phase === null ? '' : $checkpoint->phase.' · ').'Checkpoint '.max(1, (int) $checkpoint->sequence).' of '.$onRoute) : $checkpoint->phase,
            'code' => (string) $checkpoint->code,
            'qr' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            'placed' => $checkpoint->latitude !== null && $checkpoint->longitude !== null,
            'radius' => $checkpoint->radius(),
            'printedBy' => (string) $viewer->name,
            'printedAt' => SiteTime::stampIn(now(), SiteTime::zone((string) $checkpoint->tenant_id), 'M j, Y g:i A'),
        ];
    }

    /** @return list<array{id: string, name: string}> */
    private function estates(User $viewer): array
    {
        $scoped = $viewer->widestScope() === AccessScope::AssignedSites;

        return OperatorEstates::estates()
            ->when($scoped, static fn ($estates) => $estates->whereIn('id', $viewer->accessibleEstateIds()))
            ->sortBy('name')
            ->map(static fn (Tenant $estate): array => ['id' => (string) $estate->getTenantKey(), 'name' => (string) $estate->name])
            ->values()
            ->all();
    }

    private function mayPlace(User $viewer, string $tenantId): bool
    {
        return in_array($tenantId, array_column($this->estates($viewer), 'id'), true);
    }

    /**
     * What `removePost()` did, in words for the person who pressed it and for the command that corrects an estate.
     *
     * @param  array{post: string, outcome: string, shifts_removed: int, officers_unposted: int, checkpoints_kept: int}  $removed
     */
    public static function removedSentence(array $removed): string
    {
        $what = $removed['outcome'] === 'deleted'
            ? $removed['post'].' is deleted: nothing was ever done at it.'
            : $removed['post'].' is retired: it is gone from the roster, the map and every list, and the shifts and gate entries recorded at it still read under its name.';

        $n = static fn (int $count, string $one, string $many): string => $count.' '.($count === 1 ? $one : $many);

        $also = array_filter([
            $removed['shifts_removed'] > 0 ? $n($removed['shifts_removed'], 'shift', 'shifts').' not yet started there '.($removed['shifts_removed'] === 1 ? 'is' : 'are').' taken off the roster' : null,
            $removed['officers_unposted'] > 0 ? $n($removed['officers_unposted'], 'officer', 'officers').' who had it as their post now '.($removed['officers_unposted'] === 1 ? 'has' : 'have').' none: post them in Guard workforce' : null,
            $removed['checkpoints_kept'] > 0 ? $n($removed['checkpoints_kept'], 'checkpoint', 'checkpoints').' on it '.($removed['checkpoints_kept'] === 1 ? 'is' : 'are').' now on the estate\'s own patrol route, with the same tags' : null,
        ]);

        return $what.($also === [] ? '' : ' '.ucfirst(implode('; ', $also)).'.');
    }

    /**
     * `removedSentence()` before the fact, for `removalPreview()`.
     *
     * @param  array{post: string, outcome: string, shifts_removed: int, officers_unposted: int, checkpoints_kept: int}  $preview
     */
    public static function wouldRemoveSentence(array $preview): string
    {
        $what = $preview['outcome'] === 'deleted'
            ? $preview['post'].' would be deleted: nothing was ever done at it.'
            : $preview['post'].' would be retired: it would go from the roster, the map and every list, and the shifts and gate entries recorded at it would still read under its name.';

        $n = static fn (int $count, string $one, string $many): string => $count.' '.($count === 1 ? $one : $many);

        $also = array_filter([
            $preview['shifts_removed'] > 0 ? $n($preview['shifts_removed'], 'shift', 'shifts').' not yet started there would be taken off the roster' : null,
            $preview['officers_unposted'] > 0 ? $n($preview['officers_unposted'], 'officer', 'officers').' who '.($preview['officers_unposted'] === 1 ? 'has' : 'have').' it as their post would have none' : null,
            $preview['checkpoints_kept'] > 0 ? $n($preview['checkpoints_kept'], 'checkpoint', 'checkpoints').' on it would join the estate\'s own patrol route, with the same tags' : null,
        ]);

        return $what.($also === [] ? '' : ' '.ucfirst(implode('; ', $also)).'.');
    }

    /** An active post of a client this viewer covers, or the refusal. */
    private function activePost(User $viewer, int $postId): Post
    {
        $post = Post::query()->whereKey($postId)->where('is_active', true)->first();

        if (! $post instanceof Post || ! $this->mayPlace($viewer, (string) $post->tenant_id)) {
            throw new DomainException('No post of a client this role covers with that id.');
        }

        return $post;
    }

    /**
     * The estate's phases, in their order, read from its own database: what a checkpoint may stand in (M27 §4.2).
     *
     * @return list<string>
     */
    private function phasesOf(string $estateId): array
    {
        $estate = Tenant::query()->find($estateId);

        if (! $estate instanceof Tenant) {
            return [];
        }

        try {
            return $estate->run(static fn (): array => Phase::query()->orderBy('sequence')->pluck('name')->map(static fn ($n): string => (string) $n)->values()->all());
        } catch (\Throwable) {
            // An estate whose database is not built yet has no phases to offer; the checkpoint is then made without one.
            return [];
        }
    }

    /** A phase for a checkpoint: one of the estate's, as the estate spells it, or none (M27 §4.2). */
    private function phaseOf(string $estateId, ?string $phase): ?string
    {
        $phase = trim((string) $phase);

        if ($phase === '') {
            return null;
        }

        foreach ($this->phasesOf($estateId) as $known) {
            if (mb_strtolower($known) === mb_strtolower($phase)) {
                return $known;
            }
        }

        throw new DomainException('"'.$phase.'" is not one of this estate\'s phases. Choose the phase the checkpoint stands in, or leave it out.');
    }

    /** The other names a post goes by, tidied: "Gate 5, Phase 5 Gate". Empty is none. */
    private static function alsoCalledOf(?string $alsoCalled, string $name): ?string
    {
        $also = trim(preg_replace('/\s+/u', ' ', (string) $alsoCalled) ?? '');

        if ($also === '') {
            return null;
        }

        if (mb_strlen($also) > Post::ALSO_CALLED_MAX) {
            throw new DomainException('The other names a post goes by fit in '.Post::ALSO_CALLED_MAX.' characters — "Gate 5, Phase 5 Gate". A description belongs in its standing orders.');
        }

        if (mb_strtolower($also) === mb_strtolower(trim($name))) {
            throw new DomainException('That is the post\'s own name. Write only the other names the estate uses for it.');
        }

        return $also;
    }

    private static function assertPosition(float $lat, float $lng): void
    {
        if (! is_finite($lat) || ! is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
            throw new DomainException('That is not a position on the map.');
        }

        // 0, 0 is in the sea off West Africa: it is what an empty field becomes, never where a gate is.
        if (abs($lat) < 0.0001 && abs($lng) < 0.0001) {
            throw new DomainException('That position is 0, 0 — the sea off West Africa, which is what an empty field becomes. Place it where the post is.');
        }
    }

    private static function assertRadius(int $radiusM): void
    {
        if ($radiusM < self::MIN_RADIUS_M || $radiusM > self::MAX_RADIUS_M) {
            throw new DomainException('A geofence radius is between '.self::MIN_RADIUS_M.' and '.number_format(self::MAX_RADIUS_M).' metres.');
        }
    }

    /**
     * Why a geofence moved (M12 §1.5) — it changes who can clock in there, so it is never unexplained. The same rule
     * for every reason audited here, and for the commands that change posts from the command line.
     */
    public static function reasonOf(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
            throw new DomainException('Say why, in a few words — "surveyed on site", "gate moved", "demonstration". Moving a geofence changes who can clock in there, and the audit log keeps the reason beside the change.');
        }

        return $reason;
    }

    /** A number a phone can dial: digits, with the spaces, dashes, brackets and leading + people type. */
    private static function phoneOf(?string $phone, ?string $example = null): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (preg_match('/^\+?[0-9 ()\-]{7,32}$/', $phone) !== 1 || strlen($digits) < 7 || strlen($digits) > 15) {
            throw new DomainException('That is not a phone number a guard\'s phone can call. Write it with its area code, e.g. '.($example ?? '876-555-0142').'.');
        }

        return $phone;
    }
}
