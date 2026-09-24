<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A module the install knows about. Its files live in the modules folder;
 * this row holds its state. Module code serves the whole install, so
 * modules are not scoped to a site.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $version
 * @property bool $enabled
 * @property CarbonImmutable $installed_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $last_error_at
 */
class Module extends Model
{
    protected $table = 'ss_modules';

    protected $fillable = ['slug', 'name', 'version', 'enabled', 'installed_at', 'last_error', 'last_error_at'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'installed_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
        ];
    }
}
