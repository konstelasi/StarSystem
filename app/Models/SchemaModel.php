<?php

namespace App\Models;

use App\Sites\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A content model as the builder sees it. StarDust knows the model only by
 * its slug (the StarDust model name) and its fields; the label, icon,
 * group, layout and permissions live here.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $stardust_model_id
 * @property string $slug
 * @property string $label
 * @property string|null $icon
 * @property string|null $group
 * @property list<string>|null $user_groups
 * @property list<array<string, mixed>>|null $layout
 * @property int $schema_rev
 * @property string $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SchemaModel extends Model
{
    use BelongsToSite;

    public const ACTIVE = 'active';

    public const DELETING = 'deleting';

    protected $table = 'ss_models';

    protected $fillable = [
        'stardust_model_id',
        'slug',
        'label',
        'icon',
        'group',
        'user_groups',
        'layout',
        'schema_rev',
        'status',
    ];

    protected $attributes = [
        'schema_rev' => 0,
        'status' => self::ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'stardust_model_id' => 'integer',
            'user_groups' => 'array',
            'layout' => 'array',
            'schema_rev' => 'integer',
        ];
    }

    /**
     * @return HasMany<SchemaField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(SchemaField::class, 'model_id')->orderBy('position')->orderBy('key');
    }

    public function isDeleting(): bool
    {
        return $this->status === self::DELETING;
    }
}
