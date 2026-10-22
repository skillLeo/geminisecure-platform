<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One member of the estate's own staff — board 37.
 *
 * NOT A GUARD, AND NEVER LINKED TO ONE. Security guards are Gemini Security
 * Limited's employees, paid centrally out of `gs_platform`'s `payroll_runs`; a
 * Property Manager, a groundskeeper, a maintenance technician and an
 * administrative assistant are this ESTATE's employees, paid out of this
 * estate's own bank account. Board 13's own intro draws that line in words and
 * `Payroll` draws it in code — see DECISIONS.md D-057.
 *
 * BEING ON THIS TABLE IS NOT PERMISSION TO READ IT. Patricia Morgan is both
 * the Property Manager — the role D-010 locks out of `payroll` entirely — and
 * employee row one on board 15. Her own salary line is exactly as invisible to
 * her signed-in session as everyone else's; a `user_id` column here would
 * invite a shortcut ("show me my own line") that the route gate has to refuse
 * regardless of whose name is on the row, so this model carries no link to
 * `users` at all.
 *
 * WHAT IS STORED IS THE WHOLE VALUE; WHAT IS SHOWN IS THE MASK. A bank account
 * number and a NIS number are needed in full to actually pay somebody and to
 * remit a statutory deduction against the right record — but board 37 prints
 * "NCB •••• 3315" and "••• •••5 208", and no board anywhere shows more.
 * `maskedBankAccount()` and `maskedNisNumber()` are the only two routes a
 * controller payload takes to either column.
 *
 * @property int $id
 * @property string $full_name
 * @property string $job_title
 * @property string $employment_type
 * @property string|null $bank_name
 * @property string|null $bank_account_number
 * @property string|null $nis_number
 * @property int $monthly_rate_minor
 * @property int $approved_pension_minor
 * @property string $currency
 * @property Carbon $employed_since
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PayrollRunLine> $payslipLines
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 *
 * @mixin \Eloquent
 */
class Employee extends Model
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const FULL_TIME = 'full_time';

    protected $fillable = [
        'full_name',
        'job_title',
        'employment_type',
        'bank_name',
        'bank_account_number',
        'nis_number',
        'monthly_rate_minor',
        'approved_pension_minor',
        'currency',
        'employed_since',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'monthly_rate_minor' => 'integer',
            'approved_pension_minor' => 'integer',
            'employed_since' => 'date',
        ];
    }

    /** @return HasMany<PayrollRunLine, $this> */
    public function payslipLines(): HasMany
    {
        return $this->hasMany(PayrollRunLine::class);
    }

    /**
     * The two-letter avatar every employee row draws — "PM", "NA", "WT", "SC".
     *
     * The first letter of each space-separated word, which is what all four
     * names on board 37 and 15 happen to need; `Vendor::initials()` handles the
     * harder case of a single run-together trade name and is not reused here
     * because a person's name and a company's are not the same kind of string.
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->full_name)) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));

        if ($words === []) {
            return '??';
        }

        if (count($words) === 1) {
            return strtoupper(substr($words[0], 0, 2));
        }

        return strtoupper(substr($words[0], 0, 1).substr($words[count($words) - 1], 0, 1));
    }

    /** "NCB •••• 3315" — the bank and the account's last four digits, nothing else. */
    public function maskedBankAccount(): ?string
    {
        if ($this->bank_name === null || $this->bank_account_number === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $this->bank_account_number) ?? '';
        $last4 = substr($digits, -4);

        return sprintf('%s •••• %s', $this->bank_name, str_pad($last4, 4, '0', STR_PAD_LEFT));
    }

    /**
     * "••• •••5 208" — board 37's own mask, reproduced exactly.
     *
     * Every one of the four rows the board draws reveals precisely the last
     * four digits, grouped as one digit then three: "•••" + "•••" + digit +
     * " " + three digits. That grouping is fixed rather than derived from the
     * stored value's own punctuation, because the board draws it identically
     * across four NIS numbers whose full form is never shown anywhere.
     */
    public function maskedNisNumber(): ?string
    {
        if ($this->nis_number === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $this->nis_number) ?? '';
        $last4 = str_pad(substr($digits, -4), 4, '0', STR_PAD_LEFT);

        return sprintf('••• •••%s %s', $last4[0], substr($last4, 1));
    }
}
