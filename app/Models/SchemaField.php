<?php

namespace App\Models;

use App\Sites\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field of a content model, keyed by the UUID the builder gave it.
 * The row is written after StarDust applies the change, so its key and
 * storage columns never run ahead of StarDust.
 *
 * @property string $id
 * @property int $tenant_id
 * @property int $model_id
 * @property int|null $stardust_field_id
 * @property string $key
 * @property string $label
 * @property string $type
 * @property string|null $helper
 * @property array<string, mixed>|null $settings
 * @property bool $filterable
 * @property bool $required
 * @property bool $shown_in_list
 * @property int $position
 * @property string|null $layout_slot
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SchemaField extends Model
{
    use BelongsToSite;

    protected $table = 'ss_fields';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'model_id',
        'stardust_field_id',
        'key',
        'label',
        'type',
        'helper',
        'settings',
        'filterable',
        'required',
        'shown_in_list',
        'position',
        'layout_slot',
    ];

    protected function casts(): array
    {
        return [
            'model_id' => 'integer',
            'stardust_field_id' => 'integer',
            'settings' => 'array',
            'filterable' => 'boolean',
            'required' => 'boolean',
            'shown_in_list' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SchemaModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(SchemaModel::class, 'model_id');
    }
}
