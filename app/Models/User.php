<?php

namespace App\Models;

use App\Mail\VerifyEmailAddress;
use App\Sites\CurrentSite;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as VerifiesEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $pending_email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_install_owner
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, VerifiesEmail;

    /**
     * Mails the link that confirms this address, in the current site's name.
     * Laravel's own notification would read as a stranger's.
     */
    public function sendEmailVerificationNotification(): void
    {
        $link = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(VerifyEmailAddress::LIFETIME_MINUTES),
            ['id' => $this->getKey(), 'hash' => sha1($this->getEmailForVerification())],
        );

        Mail::to($this->email)->send(new VerifyEmailAddress(app(CurrentSite::class)->get()->name, $this->email, $link));
    }

    /**
     * This account's memberships. Like every site-scoped model, it sees only
     * the current site's.
     *
     * @return HasMany<SiteUser, $this>
     */
    public function siteUsers(): HasMany
    {
        return $this->hasMany(SiteUser::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_install_owner' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
