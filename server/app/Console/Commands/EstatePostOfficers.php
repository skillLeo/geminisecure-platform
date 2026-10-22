<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Guard;
use App\Models\Post;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Gemini\SiteGeography;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan estate:post-officers --estate=phoenixparkvillage1 --post="Main Gate" --officer=GS-2003 --officer=GS-2007 --reason="…"
 *   … --confirm
 *
 * OFFICERS GIVEN A POST OF THEIR OWN ESTATE, BY NUMBER (4 October 2026). The console's Reassign does this for a
 * Director (`GuardWorkforce::reassign`); this makes the same move between posts of one client for an operator who
 * signs in as nobody. The officer keeps their client, and their shifts stay as they are, as the console's move
 * within one client leaves them. Each move is audited as the console audits it, `guard.reassigned`, by "System",
 * with the reason given.
 *
 * All or nothing: an officer of another client, a suspended one, a number nobody has, or a post the estate does not
 * have refuses the whole run before anybody is moved. An officer who already has the post is said so and left
 * alone, so running it again proves it is done: "Nothing to change".
 */
class EstatePostOfficers extends Command
{
    protected $signature = 'estate:post-officers
        {--estate= : the estate (phoenixparkvillage1 on production)}
        {--post= : the post they are to have, by its name as the estate\'s list shows it}
        {--officer=* : an officer\'s employee number, such as GS-2003; once for each officer}
        {--reason= : why, in a few words the audit log keeps beside each move}
        {--confirm : make the moves; without it, only say what would change}';

    protected $description = 'Give named officers one of their own estate\'s posts, as the console\'s Reassign does';

    public function handle(AuditLogger $audit): int
    {
        $key = (string) $this->option('estate');
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('Say which estate: --estate=phoenixparkvillage1. There is no estate "'.$key.'".');

            return self::FAILURE;
        }

        try {
            $reason = SiteGeography::reasonOf((string) $this->option('reason'));
        } catch (DomainException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $name = trim((string) $this->option('post'));
        $matches = Post::query()->where('tenant_id', $key)->where('is_active', true)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->get();

        if ($matches->count() !== 1) {
            $this->error($estate->name.' ('.$key.') has '.($matches->isEmpty() ? 'no post' : $matches->count().' posts named').' "'.$name.'". Nobody is moved.');

            return self::FAILURE;
        }

        $post = $matches->first();
        $numbers = array_values(array_unique(array_filter(array_map(static fn ($n): string => strtoupper(trim((string) $n)), (array) $this->option('officer')))));

        if ($numbers === []) {
            $this->error('Name at least one officer: --officer=GS-2003. Nobody is moved.');

            return self::FAILURE;
        }

        $officers = Guard::query()->whereIn('employee_number', $numbers)->get()->keyBy(static fn (Guard $g): string => strtoupper((string) $g->employee_number));
        $refused = [];

        foreach ($numbers as $number) {
            $guard = $officers->get($number);

            if ($guard === null) {
                $refused[] = $number.': no officer has that number';
            } elseif ((string) $guard->tenant_id !== $key) {
                $refused[] = $number.' '.$guard->full_name.': works for '.($guard->tenant_id === null ? 'no client' : 'another client').', not '.$estate->name;
            } elseif ($guard->status === 'suspended') {
                $refused[] = $number.' '.$guard->full_name.': suspended from duty, so they are posted nowhere until the suspension is lifted';
            }
        }

        if ($refused !== []) {
            $this->error('Nobody is moved:');
            foreach ($refused as $line) {
                $this->line('  '.$line);
            }

            return self::FAILURE;
        }

        $apply = (bool) $this->option('confirm');
        $this->info(($apply ? 'Posting officers at ' : 'What would change at ').$estate->name.' ('.$key.')'.($apply ? '' : '. Nothing is changed without --confirm.'));

        $moves = [];

        foreach ($numbers as $number) {
            $guard = $officers->get($number);

            if ((int) $guard->post_id === (int) $post->id) {
                $this->line('  '.$number.' '.$guard->full_name.': already has '.$post->label());

                continue;
            }

            $from = $guard->post_id === null ? 'no post' : (Post::query()->whereKey($guard->post_id)->first()?->label() ?? 'post '.$guard->post_id);
            $this->line('  '.$number.' '.$guard->full_name.': '.$from.' → '.$post->label());
            $moves[] = $guard;
        }

        if ($moves === []) {
            $this->newLine();
            $this->info('Nothing to change. Its officers by post: '.self::officersByPost($key).'.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->line('  The reason the audit log would keep: '.$reason);

            return self::SUCCESS;
        }

        DB::connection('mysql')->transaction(function () use ($moves, $post, $key, $reason, $audit): void {
            foreach ($moves as $guard) {
                $before = ['tenant_id' => $guard->tenant_id, 'post_id' => $guard->post_id];
                $guard->forceFill(['post_id' => $post->id])->save();

                $audit->record(
                    action: 'guard.reassigned',
                    entityType: 'Guard',
                    entityId: (string) $guard->id,
                    before: $before,
                    after: [
                        'tenant_id' => $key,
                        'post_id' => (int) $post->id,
                        'shifts_released' => 0,
                        'by' => AuditLogger::SYSTEM_ACTOR,
                        'reason' => $reason,
                        'via' => 'estate:post-officers',
                    ],
                    tenantId: $key,
                );
            }
        });

        $count = count($moves);
        $this->newLine();
        $this->info('Done. '.$count.' officer'.($count === 1 ? ' now has ' : 's now have ').$post->label().' as their post. Its officers by post now: '.self::officersByPost($key).'.');

        return self::SUCCESS;
    }

    /** How many of the estate's officers have each post, and how many have none. */
    private static function officersByPost(string $key): string
    {
        // Plain rows, not guards: what comes back is a count per post.
        return Guard::query()->toBase()->where('guards.tenant_id', $key)
            ->leftJoin('posts', 'posts.id', '=', 'guards.post_id')
            ->selectRaw('posts.name AS post, posts.also_called AS also, COUNT(*) AS officers')
            ->groupBy('posts.name', 'posts.also_called')->orderBy('posts.name')->get()
            ->map(static fn ($row): string => ($row->post === null ? 'no post' : Post::labelFor((string) $row->post, $row->also)).' '.$row->officers)
            ->implode(', ');
    }
}
