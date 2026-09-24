<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A site. Its id is the StarDust tenant id and the tenant_id on every
 * site-scoped ss_* table.
 *
 * @property int $id
 * @property string $name
 * @property list<string> $domains
 * @property string|null $path_prefix
 * @property string $locale
 * @property string|null $theme
 * @property array<string, mixed>|null $settings
 */
class Site extends Model
{
    protected $table = 'ss_sites';

    protected $fillable = ['name', 'domains', 'path_prefix', 'locale', 'theme', 'settings'];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'settings' => 'array',
        ];
    }

    public static function forHost(string $host): ?self
    {
        return static::query()->whereJsonContains('domains', strtolower($host))->first();
    }
}
