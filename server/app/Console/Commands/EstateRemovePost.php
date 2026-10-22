<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Tenant;
use App\Services\Gemini\SiteGeography;
use DomainException;
use Illuminate\Console\Command;

/**
 *   php artisan estate:remove-post --estate=phoenixparkvillage1 --post=bla --reason="…"             what would happen
 *   php artisan estate:remove-post --estate=phoenixparkvillage1 --post=bla --reason="…" --confirm   remove it
 *
 * A POST TAKEN AWAY BY NAME, WITH A REASON (4 October 2026). The console's Remove does this for a Director; this does
 * it for an operator who is told to and signs in as nobody. It is the console's own removal (`SiteGeography::remove`):
 * deleted when nothing was ever done at the post, retired when something was, its un-started shifts off the roster
 * and its officers left with no post, audited by "System" with the reason given. Without --confirm it says what would
 * happen, in the same words, and changes nothing.
 */
class EstateRemovePost extends Command
{
    protected $signature = 'estate:remove-post
        {--estate= : the estate (phoenixparkvillage1 on production)}
        {--post= : the post, by its name as the estate\'s list shows it}
        {--reason= : why, in a few words the audit log keeps beside the removal}
        {--confirm : remove it; without it, only say what would happen}';

    protected $description = 'Remove one of an estate\'s posts by name, with a reason, as the console\'s Remove does';

    public function handle(SiteGeography $geography): int
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
            $this->error(($matches->isEmpty()
                ? $estate->name.' ('.$key.') has no post "'.$name.'"'
                : $estate->name.' has '.$matches->count().' posts named "'.$name.'": remove the one meant in the console, where each is shown')
                .'. Nothing is removed. Its posts: '.self::posts($key).'.');

            return self::FAILURE;
        }

        $post = $matches->first();

        if (! $this->option('confirm')) {
            $this->info('What would happen at '.$estate->name.' ('.$key.'). Nothing is changed without --confirm.');
            $this->line('  '.SiteGeography::wouldRemoveSentence($geography->removalPreview($post)));
            $this->line('  The reason the audit log would keep: '.$reason);

            return self::SUCCESS;
        }

        $this->info('Removing a post at '.$estate->name.' ('.$key.')');
        $this->line('  '.SiteGeography::removedSentence($geography->remove($post, $reason)));
        $this->newLine();
        $this->info('Done. Its posts now: '.self::posts($key).'.');

        return self::SUCCESS;
    }

    /** The estate's posts, as `phoenix:two-gates` prints them. */
    private static function posts(string $key): string
    {
        $posts = Post::query()->where('tenant_id', $key)->where('is_active', true)->orderBy('name')->get();

        return $posts->isEmpty() ? 'none' : $posts->map(static fn (Post $p): string => $p->label().($p->isPlaced() ? ' (placed)' : ' (not placed)'))->implode(', ');
    }
}
