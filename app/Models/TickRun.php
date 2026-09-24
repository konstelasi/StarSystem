<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One StarDust tick. The tick serves the whole install, so runs are not
 * scoped to a site.
 *
 * @property int $id
 * @property string $trigger
 * @property int|null $rounds
 * @property int|null $budget_seconds
 * @property float|null $elapsed_seconds
 * @property string|null $stop_reason
 * @property string|null $error
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
class TickRun extends Model
{
    protected $table = 'ss_tick_runs';

    public $timestamps = false;

    protected $fillable = [
        'trigger',
        'rounds',
        'budget_seconds',
        'elapsed_seconds',
        'stop_reason',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'rounds' => 'integer',
            'budget_seconds' => 'integer',
            'elapsed_seconds' => 'float',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
