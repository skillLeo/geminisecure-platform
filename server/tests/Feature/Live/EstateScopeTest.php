<?php

declare(strict_types=1);

use App\Live\Auth\ChannelRegistry;
use App\Live\Auth\EstateScope;
use App\Live\Auth\Principal;
use App\Live\Auth\Rules\Rule;
use App\Live\ChannelName;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyInitialized;
use Tests\Live\Support\LiveAuthFixture;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| AN ESTATE OPENED FOR A RULE IS ALWAYS CLOSED AGAIN (M11 Part C, DESIGN §3.5)
|--------------------------------------------------------------------------
|
| `Tenant::run()` has no `finally`, so a callback that throws leaves the estate open
| and whatever runs next reads that estate's database. `EstateScope::run()` is the same
| act with the restore made unconditional.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    tenancy()->end();

    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    tenancy()->end();

    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

it('opens the estate for the callback, hands back its answer, and closes it again', function () {
    $seen = EstateScope::run(FacilitiesFixture::ESTATE, fn (): array => [
        tenancy()->initialized,
        (string) tenant()?->getTenantKey(),
        DB::connection('tenant')->getDatabaseName(),
    ]);

    expect($seen)->toBe([true, FacilitiesFixture::ESTATE, FacilitiesFixture::database()])
        ->and(tenancy()->initialized)->toBeFalse();
});

it('closes the estate when the callback throws, where Tenant::run() leaves it open', function () {
    expect(fn () => EstateScope::run(FacilitiesFixture::ESTATE, function (): never {
        throw new RuntimeException('the rule failed');
    }))->toThrow(RuntimeException::class, 'the rule failed');

    expect(tenancy()->initialized)->toBeFalse();

    // The reason this class exists, shown rather than asserted in a comment.
    $estate = Tenant::query()->findOrFail(FacilitiesFixture::ESTATE);

    try {
        $estate->run(function (): never {
            throw new RuntimeException('the rule failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(tenancy()->initialized)->toBeTrue('stancl\'s own run() has no finally; if this changes, EstateScope is still correct');
});

it('puts back the estate that was open before, whether the callback returns or throws', function () {
    $twin = LiveAuthFixture::twinEstate();

    tenancy()->initialize(Tenant::query()->findOrFail(FacilitiesFixture::ESTATE));

    $inside = EstateScope::run($twin, fn (): string => (string) tenant()?->getTenantKey());

    expect($inside)->toBe($twin)
        ->and((string) tenant()?->getTenantKey())->toBe(FacilitiesFixture::ESTATE);

    expect(fn () => EstateScope::run($twin, function (): never {
        throw new RuntimeException('the rule failed');
    }))->toThrow(RuntimeException::class);

    expect((string) tenant()?->getTenantKey())->toBe(FacilitiesFixture::ESTATE);
});

it('runs inside an estate that is already open without closing it', function () {
    tenancy()->initialize(Tenant::query()->findOrFail(FacilitiesFixture::ESTATE));

    expect(EstateScope::run(FacilitiesFixture::ESTATE, fn (): bool => tenancy()->initialized))->toBeTrue()
        ->and(tenancy()->initialized)->toBeTrue()
        ->and((string) tenant()?->getTenantKey())->toBe(FacilitiesFixture::ESTATE);
});

it('refuses to open an estate that does not exist, and leaves nothing open', function () {
    expect(fn () => EstateScope::run('zzzq', fn (): bool => true))->toThrow(RuntimeException::class);

    expect(tenancy()->initialized)->toBeFalse();
});

it('refuses a channel whose rule throws, and ends the tenancy that rule was given', function () {
    $throws = new class implements Rule
    {
        public bool $asked = false;

        public function central(Principal $principal, ChannelName $channel): bool
        {
            return true;
        }

        public function needsEstate(): bool
        {
            return true;
        }

        public function estate(Principal $principal, ChannelName $channel): bool
        {
            $this->asked = tenancy()->initialized && (string) tenant()?->getTenantKey() === $channel->tenant;

            throw new RuntimeException('a rule that fails');
        }
    };

    $registry = new ChannelRegistry([ChannelName::ESTATE_FEED => $throws]);
    $president = Principal::web(FacilitiesFixture::viewer(Role::PRESIDENT));
    $t = FacilitiesFixture::ESTATE;

    expect($registry->decide($president, "estate.{$t}.security"))->toBeFalse()
        ->and($throws->asked)->toBeTrue('the rule ran inside the estate it was asked about')
        ->and(tenancy()->initialized)->toBeFalse('and that estate is closed again');

    // With the handset's own estate already open, as ResolveDevice leaves it, that estate stays open.
    tenancy()->initialize(Tenant::query()->findOrFail($t));

    expect($registry->decide($president, "estate.{$t}.security"))->toBeFalse()
        ->and((string) tenant()?->getTenantKey())->toBe($t);

    tenancy()->end();

    // A rule that throws before any estate is opened is a refusal too.
    $centralThrows = new class implements Rule
    {
        public function central(Principal $principal, ChannelName $channel): bool
        {
            throw new RuntimeException('a central check that fails');
        }

        public function needsEstate(): bool
        {
            return false;
        }

        public function estate(Principal $principal, ChannelName $channel): bool
        {
            return true;
        }
    };

    $own = 'user.'.$president->user?->id;

    expect(app(ChannelRegistry::class)->decide($president, $own))->toBeTrue('the control: the registered rule admits a user to their own channel')
        ->and((new ChannelRegistry([ChannelName::USER => $centralThrows]))->decide($president, $own))->toBeFalse();
});

it('opens no estate for a channel the central checks refuse, and each estate once for all of its channels', function () {
    $t = FacilitiesFixture::ESTATE;
    $twin = LiveAuthFixture::twinEstate();
    $president = Principal::web(FacilitiesFixture::viewer(Role::PRESIDENT));

    $opened = [];
    Event::listen(TenancyInitialized::class, function (TenancyInitialized $event) use (&$opened): void {
        $opened[] = (string) $event->tenancy->tenant?->getTenantKey();
    });

    $answers = app(ChannelRegistry::class)->decideMany($president, [
        "estate.{$twin}.security",
        "estate.{$twin}.facilities.ticket.4",
        "estate.{$t}.security",
        "estate.{$t}.facilities",
        "estate.{$t}.facilities.ticket.4",
        "estate.{$t}.governance.meeting.2",
    ]);

    expect($answers)->toBe([
        "estate.{$twin}.security" => false,
        "estate.{$twin}.facilities.ticket.4" => false,
        "estate.{$t}.security" => true,
        "estate.{$t}.facilities" => true,
        "estate.{$t}.facilities.ticket.4" => true,
        "estate.{$t}.governance.meeting.2" => true,
    ])->and($opened)->toBe([$t], 'the estate the president does not belong to was never opened, and their own was opened once');
});
