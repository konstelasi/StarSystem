<?php

namespace App\Models;

use App\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * A logged schema operation, so a half-finished builder save can be shown
 * and retried.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $model_id
 * @property string $op
 * @property string|null $field_id
 * @property string $status
 * @property string|null $error
 */
class SchemaChange extends Model
{
    use BelongsToSite;

    protected $table = 'ss_schema_changes';

    protected $fillable = ['model_id', 'op', 'field_id', 'status', 'error'];
}
