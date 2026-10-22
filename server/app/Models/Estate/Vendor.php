<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A supplier the estate buys from — boards 26 and 39.
 *
 * THE REGISTER, not the money. What this vendor is owed is the balance of its
 * lines on 2000 Accounts Payable, and what it has been paid is the debits on the
 * same account; neither is stored here, for the same reason no account carries a
 * balance. This row holds what bookkeeping has no place for — a trade, a phone
 * number, and a taxpayer registration number.
 *
 * THE TRN IS REQUIRED AT PAYMENT, NOT AT RECORDING, and the column is nullable
 * because of it. A bill arrives whether or not the supplier's paperwork is in
 * order; refusing to record it would understate what the estate owes, which is
 * the one thing a payables ledger exists to state. Withholding compliance bites
 * when money actually moves, and `Payables::pay()` is where it bites.
 *
 * `category` HOLDS THE TRADE, NOT THE LEDGER GROUPING. Board 39 calls "Electrical
 * contractor" the vendor's category and board 26 draws it as the subtitle under
 * the name, so that is what is stored. The coarse Maintenance/Utilities column
 * board 26 also draws is NOT stored beside it: it is which expense account this
 * vendor's bills actually post to, read back from the journal by
 * `Payables::expenseAccountsByVendor()`. A second copy kept here would be free to
 * say "Maintenance" about a vendor whose costs all landed on 5200.
 *
 * DEACTIVATED, NEVER DELETED. A vendor with bills against it is history the
 * estate has to keep, and a restricting foreign key on `bills.vendor_id` says so
 * at the database.
 *
 * @property int $id
 * @property string $name
 * @property string|null $category
 * @property string|null $trn
 * @property string|null $contact_name
 * @property string|null $contact_phone
 * @property string|null $contact_email
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Bill> $bills
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Vendor query()
 *
 * @mixin \Eloquent
 */
class Vendor extends Model
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    /** The category a utility company carries (the electricity company's, in the seed's `trade`): bills, never work orders. */
    public const UTILITIES = 'Utilities';

    protected $fillable = [
        'name',
        'category',
        'trn',
        'contact_name',
        'contact_phone',
        'contact_email',
        'status',
    ];

    /** @return HasMany<Bill, $this> */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /**
     * Whether this vendor may be paid at all.
     *
     * A blank string is as absent as a null — a TRN field somebody tabbed
     * through is not a TRN on file, and the payment refusal has to read the same
     * way for both.
     */
    /**
     * THE SUPPLIERS A WORK ORDER CAN BE GIVEN TO (DR-035, M62 A8): the active ones, less the utilities. The electricity
     * company bills the estate every month and is kept for that, but it is not a contractor anybody assigns a broken
     * gate to. A supplier with no category is offered, as before.
     *
     * @param  Builder<Vendor>  $query
     * @return Builder<Vendor>
     */
    public function scopeTakesMaintenanceWork(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)
            ->where(static fn (Builder $q) => $q->whereNull('category')->orWhere('category', '!=', self::UTILITIES));
    }

    public function hasTrn(): bool
    {
        return trim((string) $this->trn) !== '';
    }

    /**
     * The two-letter avatar every vendor row draws.
     *
     * The first letter, then the next capital or digit inside the name once the
     * spaces are closed up — which is what makes "AquaTech Pool Services" read
     * "AT" rather than "AP", and "Gate2 Contractor Ltd" read "G2". Board 26 draws
     * exactly that for four of its five vendors.
     *
     * CONTENT RESIDUAL: the board draws "JU" against "Jamaica Public Service Co.",
     * which no rule over that name produces — this returns "JP". Recorded rather
     * than special-cased, because a lookup table of exceptions to a two-letter
     * initial is a maintenance burden that outlives the wireframe it came from.
     */
    public function initials(): string
    {
        $letters = preg_replace('/[^A-Za-z0-9]/', '', $this->name) ?? '';

        if ($letters === '') {
            return '??';
        }

        $second = '';

        for ($i = 1, $length = strlen($letters); $i < $length; $i++) {
            if (ctype_upper($letters[$i]) || ctype_digit($letters[$i])) {
                $second = $letters[$i];
                break;
            }
        }

        // Nothing capitalised further in — a single lower-case word — so the
        // second letter of the word itself, which is what an avatar needs.
        return strtoupper($letters[0].($second !== '' ? $second : substr($letters, 1, 1)));
    }

    /**
     * The contact cell as board 26 composes it.
     *
     * A named person and a phone joined by a middot; a bare phone where there is
     * no name; an email where there is neither. The estate reaches a supplier by
     * whatever it has, and an empty cell reads as a broken row rather than as a
     * missing phone number.
     */
    public function contactLine(): string
    {
        $phone = trim((string) $this->contact_phone);
        $name = trim((string) $this->contact_name);

        if ($phone !== '') {
            return $name !== '' ? $name.' · '.$phone : $phone;
        }

        return trim((string) $this->contact_email) !== ''
            ? (string) $this->contact_email
            : ($name !== '' ? $name : 'No contact on file');
    }
}
