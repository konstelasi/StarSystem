<?php

namespace App\Sites;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every site-scoped ss_* model: reads only see the current site's rows,
 * and new rows get the current site's tenant_id.
 *
 * One missed query shows one site's data on another, so every feature
 * that uses this trait gets a cross-site test (see tests/Concerns/CrossSite).
 *
 * Using models declare `@property int $tenant_id`.
 *
 * @mixin Model
 */
trait BelongsToSite
{
    public static function bootBelongsToSite(): void
    {
        static::addGlobalScope('site', function (Builder $query) {
            $query->where($query->qualifyColumn('tenant_id'), app(CurrentSite::class)->tenantId());
        });

        static::creating(function (self $model) {
            $model->tenant_id ??= app(CurrentSite::class)->tenantId();
        });
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'tenant_id');
    }
}
