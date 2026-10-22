<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AlertEmailDispatch;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Estate\ResidentInvites;
use App\Services\ResidentApp\AccountPhotos;
use App\Services\ResidentApp\ResidentPassCancellation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan household:test-rows --estate=phoenixparkvillage1 --household=92                    what is on it, and who put it there
 *   php artisan household:test-rows --estate=phoenixparkvillage1 --household=92 --remove=member:612,vehicle:33,contact:18,pet:4,photo:77
 *                                                                                                   what removing those would do
 *   … --remove=… --confirm                                                                           do it
 *
 * THE TEST DATA ON SCREEN IN FRONT OF THE CLIENT (M50 §6.13). The user's household showed "Asdfg", "Bshsj", "Skillllls",
 * two identical cars, an emergency contact "Bhai · Bro" with a Pakistani number and a photograph of a car wheel as the
 * profile photo. Some of that was typed into the app while testing; some the demonstration seeded. This command says
 * which, row by row — SEEDED (by which seeding command, `seeded_by`) or TYPED INTO THE APP (by which app account, when) —
 * so a person decides what goes. It removes ONLY the rows named in `--remove`, and only with `--confirm`:
 *
 *   member:<id>    removed from the household as the primary removes one in the app — kept with its history, their
 *                  invite cancelled, their app access ended, their own e-Pass cancelled. Never the primary.
 *   vehicle:<id>   deleted from the household's list (no gate decision reads it)
 *   contact:<id>   deleted from the household's emergency contacts
 *   pet:<id>       deleted from the household's pets
 *   photo:<id>     the profile photo of app account <id> (one of this household's), deleted
 *
 * Every removal is audited. It is run on production by a person who has read the dry run — never by a deploy, and
 * never by the assistant.
 */
class HouseholdTestRows extends Command
{
    protected $signature = 'household:test-rows
        {--estate= : the estate (phoenixparkvillage1 on production)}
        {--household= : the household id}
        {--remove= : what to remove, e.g. member:612,vehicle:33,contact:18,pet:4,photo:77}
        {--confirm : make the removals; without it, only say what they would be}';

    protected $description = 'List a household\'s people, vehicles, contacts, pets and photos as seeded or typed in the app, and remove the ones named';

    private const KINDS = ['member', 'vehicle', 'contact', 'pet', 'photo'];

    public function handle(AuditLogger $audit, ResidentInvites $invites, AccountPhotos $photos): int
    {
        $key = (string) $this->option('estate');
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('Say which estate: --estate=phoenixparkvillage1. There is no estate "'.$key.'". Nothing was changed.');

            return self::INVALID;
        }

        $household = (string) $this->option('household');

        if (! ctype_digit($household)) {
            $this->error('Say which household: --household=92 (its id, digits only). Nothing was changed.');

            return self::INVALID;
        }

        $remove = $this->removals((string) $this->option('remove'));

        if ($remove === null) {
            $this->error('--remove takes kind:id pairs separated by commas, the kinds being '.implode(', ', self::KINDS).' — e.g. member:612,vehicle:33. Nothing was changed.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('confirm');

        return $estate->run(function () use ($key, $estate, $household, $remove, $apply, $audit, $invites, $photos): int {
            $home = Household::query()->with('unit')->find((int) $household);

            if (! $home instanceof Household) {
                $this->error('There is no household '.$household.' at '.$estate->name.'. Nothing was changed.');

                return self::INVALID;
            }

            $this->info('Household '.$home->id.' · '.($home->unit->reference ?? 'no unit').' · '.$home->name.' at '.$estate->name.' ('.$key.')');
            $rows = $this->rows($home, $key);
            $this->list($rows);

            if ($remove === []) {
                $this->newLine();
                $this->info('Nothing named to remove. Name rows with --remove=kind:id,… to see what removing them would do.');

                return self::SUCCESS;
            }

            // Every row named must be one listed above, or nothing is changed at all.
            $listed = [];
            foreach ($rows as $kind => $items) {
                foreach ($items as $item) {
                    $listed[$kind.':'.$item['id']] = $item;
                }
            }

            foreach ($remove as $named) {
                if (! isset($listed[$named])) {
                    $this->error($named.' is not one of this household\'s rows listed above. Nothing was changed.');

                    return self::INVALID;
                }

                if (str_starts_with($named, 'member:') && $listed[$named]['primary']) {
                    $this->error($named.' is the household\'s primary resident and is never removed here. Nothing was changed.');

                    return self::INVALID;
                }
            }

            $this->newLine();
            $this->info($apply ? 'Removing:' : 'What removing would do (nothing is changed without --confirm):');

            foreach ($remove as $named) {
                [$kind, $id] = explode(':', $named);
                $item = $listed[$named];
                $this->line('  '.$named.' · '.$item['what'].' — '.$this->effect($kind));

                if ($apply) {
                    $this->apply($kind, (int) $id, $home, $item, $key, $audit, $invites, $photos);
                }
            }

            $this->newLine();
            $this->info($apply ? 'Done: '.count($remove).' removed, each on the audit log.' : count($remove).' to remove. Nothing is changed without --confirm.');

            return self::SUCCESS;
        });
    }

    /** @return list<string>|null the named rows, `kind:id` each — null when the option says something else */
    private function removals(string $option): ?array
    {
        if (trim($option) === '') {
            return [];
        }

        $named = [];
        foreach (explode(',', $option) as $part) {
            $part = strtolower(trim($part));
            if (! preg_match('/^('.implode('|', self::KINDS).'):(\d+)$/', $part)) {
                return null;
            }
            $named[] = $part;
        }

        return array_values(array_unique($named));
    }

    /**
     * Who put each row there: a seeding command (`seeded_by`), or an app account (`added_by_account_id`), or the office.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function rows(Household $home, string $key): array
    {
        $estate = DB::connection('tenant');
        $accounts = ResidentAccount::query()->where('tenant_id', $key)->get()->keyBy('id');
        $byAccount = static fn (mixed $id): string => $id === null ? 'the estate office or before the app said who' : 'app account '.$id.(isset($accounts[(int) $id]) ? ' ('.$accounts[(int) $id]->full_name.')' : '');
        $origin = static fn (object $row, string $typed): string => ($row->seeded_at ?? null) !== null
            ? 'SEEDED by '.($row->seeded_by ?? 'a seeding command').' on '.Carbon::parse((string) $row->seeded_at)->toDateString()
            : $typed.', '.Carbon::parse((string) $row->created_at)->toDateString();

        $people = Resident::query()->where('household_id', $home->id)->orderByDesc('is_primary')->orderBy('id')->get();
        // The marks are never handed back by a `*` read (SampleAwareBuilder): asked for by name.
        $marks = $estate->table('residents')->whereIn('id', $people->modelKeys())->get(['id', 'seeded_at', 'seeded_by', 'created_at'])->keyBy('id');
        // Who added a member in the app is on the audit log (`household.member_added`), not on the row.
        $addedInApp = DB::connection('mysql')->table('audit_log')
            ->where('tenant_id', $key)->where('action', 'household.member_added')
            ->whereIn('entity_id', $people->map(static fn (Resident $r): string => (string) $r->id)->all())
            ->get(['entity_id', 'after'])
            ->mapWithKeys(static fn (object $e): array => [(string) $e->entity_id => (array) json_decode((string) $e->after, true)]);

        $members = $people
            ->map(function (Resident $r) use ($origin, $accounts, $addedInApp, $marks): array {
                $signsIn = $accounts->first(static fn (ResidentAccount $a): bool => (int) $a->resident_id === (int) $r->id);
                $added = $addedInApp[(string) $r->id] ?? null;
                $typed = $added === null
                    ? 'ENTERED by the estate office or a claim'
                    : 'TYPED in the app by '.($added['by'] ?? 'a resident').(isset($added['by_account_id']) ? ' (app account '.$added['by_account_id'].')' : '');

                return [
                    'id' => (int) $r->id,
                    // The address masked (M52 §3.3): enough for the owner to see whose it is and who a panic would email.
                    'what' => $r->full_name.' · '.($r->relationship ?: '—').($r->is_primary ? ' · PRIMARY' : '').' · '.self::masked($r->email),
                    'primary' => (bool) $r->is_primary,
                    'origin' => $origin($marks[$r->id] ?? (object) ['seeded_at' => null, 'created_at' => $r->created_at], $typed),
                    'note' => $signsIn instanceof ResidentAccount ? 'signs in as app account '.$signsIn->id.($signsIn->getAttribute('photo_path') ? ' · has a profile photo (photo:'.$signsIn->id.')' : '') : 'no app account',
                ];
            })->all();

        $vehicles = $estate->table('household_vehicles')->where('household_id', $home->id)->orderBy('id')
            ->get(['id', 'plate', 'make', 'model', 'colour', 'added_by_account_id', 'created_at', 'seeded_at', 'seeded_by'])
            ->map(fn (object $v): array => ['id' => (int) $v->id, 'what' => trim($v->plate.' · '.($v->colour ?? '').' '.($v->make ?? '').' '.($v->model ?? '')), 'origin' => $origin($v, 'TYPED in the app by '.$byAccount($v->added_by_account_id ?? null)), 'note' => ''])->all();

        $contacts = $estate->table('household_emergency_contacts')->where('household_id', $home->id)->orderBy('id')
            ->get(['id', 'name', 'relationship', 'phone', 'email', 'added_by_account_id', 'created_at', 'seeded_at', 'seeded_by'])
            ->map(fn (object $c): array => ['id' => (int) $c->id, 'what' => $c->name.' · '.($c->relationship ?? '—').' · '.$c->phone.' · '.self::masked($c->email ?? null), 'origin' => $origin($c, 'TYPED in the app by '.$byAccount($c->added_by_account_id ?? null)), 'note' => ''])->all();

        $pets = $estate->getSchemaBuilder()->hasTable('household_pets')
            ? $estate->table('household_pets')->where('household_id', $home->id)->orderBy('id')
                ->get(['id', 'name', 'kind', 'added_by_account_id', 'created_at', 'seeded_at', 'seeded_by'])
                ->map(fn (object $p): array => ['id' => (int) $p->id, 'what' => $p->name.' · '.$p->kind, 'origin' => $origin($p, 'TYPED in the app by '.$byAccount($p->added_by_account_id ?? null)), 'note' => ''])->all()
            : [];

        $residentIds = array_map(static fn (array $m): int => $m['id'], $members);
        $photos = $accounts->filter(static fn (ResidentAccount $a): bool => in_array((int) $a->resident_id, $residentIds, true) && (string) $a->getAttribute('photo_path') !== '')
            ->map(static fn (ResidentAccount $a): array => ['id' => (int) $a->id, 'what' => 'profile photo of '.$a->full_name, 'origin' => 'TAKEN or CHOSEN in the app, '.Carbon::parse((string) $a->getAttribute('photo_updated_at'))->toDateString(), 'note' => ''])
            ->values()->all();

        return ['member' => $members, 'vehicle' => $vehicles, 'contact' => $contacts, 'pet' => $pets, 'photo' => $photos];
    }

    private static function masked(mixed $email): string
    {
        return is_string($email) && trim($email) !== '' ? AlertEmailDispatch::mask($email) : 'no email';
    }

    /** @param array<string, list<array<string, mixed>>> $rows */
    private function list(array $rows): void
    {
        foreach ($rows as $kind => $items) {
            $this->newLine();
            $this->line(ucfirst($kind).'s: '.count($items));

            foreach ($items as $item) {
                $this->line('  '.$kind.':'.$item['id'].'  '.$item['what'].'  — '.$item['origin'].($item['note'] !== '' ? ' · '.$item['note'] : ''));
            }
        }
    }

    private function effect(string $kind): string
    {
        return match ($kind) {
            'member' => 'removed from the household as the app removes one: kept with its history, invite cancelled, app access ended, e-Pass cancelled',
            'vehicle' => 'deleted from the household\'s vehicles',
            'contact' => 'deleted from the household\'s emergency contacts',
            'pet' => 'deleted from the household\'s pets',
            'photo' => 'the profile photo deleted',
            default => 'nothing',
        };
    }

    /** @param array<string, mixed> $item */
    private function apply(string $kind, int $id, Household $home, array $item, string $key, AuditLogger $audit, ResidentInvites $invites, AccountPhotos $photos): void
    {
        $estate = DB::connection('tenant');

        match ($kind) {
            'member' => (function () use ($id, $invites): void {
                $person = Resident::query()->findOrFail($id);
                $person->forceFill(['removed_at' => Carbon::now()])->save();
                $invites->cancel($person, 'household:test-rows', null, 'member removed');
                ResidentAccount::query()->where('resident_id', $id)->where('status', '!=', ResidentAccount::REVOKED)->get()
                    ->each(static function (ResidentAccount $account): void {
                        $account->tokens()->delete();
                        $account->forceFill(['status' => ResidentAccount::SUSPENDED])->save();
                    });
                app(ResidentPassCancellation::class)->cancelFor($id);
            })(),
            'vehicle' => $estate->table('household_vehicles')->where('household_id', $home->id)->where('id', $id)->delete(),
            'contact' => $estate->table('household_emergency_contacts')->where('household_id', $home->id)->where('id', $id)->delete(),
            'pet' => $estate->table('household_pets')->where('household_id', $home->id)->where('id', $id)->delete(),
            'photo' => $photos->remove(ResidentAccount::query()->findOrFail($id)),
            default => null,
        };

        $audit->record(
            action: 'household.test_row_removed',
            entityType: 'Household',
            entityId: (string) $home->id,
            before: ['row' => $kind.':'.$id, 'what' => $item['what'], 'origin' => $item['origin']],
            after: ['removed' => true, 'via' => 'household:test-rows', 'reason' => 'test data on screen (M50 §6.13)'],
            tenantId: $key,
        );
    }
}
