<?php

namespace App\Models;

use App\Sites\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's membership of one site, and the role it holds there.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $role_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SiteUser extends Model
{
    use BelongsToSite;

    protected $table = 'ss_site_users';

    protected $fillable = ['user_id', 'role_id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
