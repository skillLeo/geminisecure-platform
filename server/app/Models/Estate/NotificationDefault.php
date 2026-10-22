<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;

/**
 * One switch on board 30 — whether ONE event notifies ONE channel, for this
 * estate's residents by default.
 *
 * `event_key` is not a foreign key. The six events are
 * `App\Services\Estate\Settings::NOTIFICATION_EVENTS`, a compile-time
 * constant rather than a table this estate administers — see the migration
 * for why. A key this model no longer recognises is dropped on read rather
 * than rendered as a mystery row, the same treatment `Settings::featuresBoard()`
 * already gives a withdrawn catalogue feature.
 *
 * @property int $id
 * @property string $event_key
 * @property string $channel
 * @property bool $enabled
 */
class NotificationDefault extends Model
{
    /** The three channels, always rendered left to right in this order. */
    public const CHANNELS = ['email', 'sms', 'push'];

    protected $fillable = [
        'event_key',
        'channel',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
