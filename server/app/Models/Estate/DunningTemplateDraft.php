<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A proposed dunning step, waiting on the committee (12 §1).
 *
 * WHAT THE ESTATE SENDS IS `dunning_templates`. This is what somebody has
 * PROPOSED it should send, and the difference is the point: a draft is never
 * read by the collections run, never rendered into a notice, and reaches the
 * ladder only when a committee resolution reference is recorded against it.
 *
 * @property int $id
 * @property int|null $dunning_template_id
 * @property string $label
 * @property int $stage
 * @property string $channel
 * @property string $subject
 * @property string $body
 * @property int $days_overdue
 * @property string|null $resolution_reference
 * @property int|null $drafted_by_id
 * @property string $drafted_by_name
 * @property Carbon|null $adopted_at
 * @property string|null $adopted_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DunningTemplate|null $template
 */
class DunningTemplateDraft extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'dunning_template_id',
        'label',
        'stage',
        'channel',
        'subject',
        'body',
        'days_overdue',
        'resolution_reference',
        'drafted_by_id',
        'drafted_by_name',
        'adopted_at',
        'adopted_by_name',
    ];

    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'days_overdue' => 'integer',
            'adopted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DunningTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DunningTemplate::class, 'dunning_template_id');
    }

    /** Whether this draft has been put in force. An adopted draft is history. */
    public function isAdopted(): bool
    {
        return $this->adopted_at !== null;
    }

    /**
     * What this draft is against, in words.
     *
     * A new step says so — "a step the estate does not have yet" is the fact a
     * reader needs before they can judge whether the days figure is sane.
     */
    public function targetLabel(): string
    {
        return $this->dunning_template_id === null
            ? $this->label.' — a new step'
            : $this->label.' — replacing the wording in force';
    }
}
