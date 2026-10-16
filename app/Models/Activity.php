<?php

namespace App\Models;

use App\Sites\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing someone did on a site. Rows are only ever added and, after the
 * retention period, deleted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_address
 * @property CarbonImmutable $created_at
 */
class Activity extends Model
{
    use BelongsToSite;

    public const UPDATED_AT = null;

    protected $table = 'ss_activity';

    protected $fillable = ['actor_id', 'actor_name', 'action', 'subject_type', 'subject_id', 'subject_label', 'properties', 'ip_address'];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
