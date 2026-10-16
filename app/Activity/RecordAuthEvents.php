<?php

namespace App\Activity;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

/**
 * Turns the framework's sign-in and two-factor events into log rows.
 *
 * The handlers look the log up when an event arrives instead of keeping it,
 * since a long-lived worker would otherwise hold on to an old site.
 */
class RecordAuthEvents
{
    /**
     * Failed sign-ins logged per address each minute. Anyone can cause one
     * without an account, so the log must not grow as fast as they can post.
     */
    private const FAILED_PER_MINUTE = 30;

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Failed::class => 'failed',
            Logout::class => 'logout',
            PasswordReset::class => 'passwordReset',
            TwoFactorAuthenticationEnabled::class => 'twoFactorEnabled',
            TwoFactorAuthenticationConfirmed::class => 'twoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'twoFactorDisabled',
            TwoFactorAuthenticationFailed::class => 'twoFactorFailed',
        ];
    }

    public function login(Login $event): void
    {
        $this->record(ActivityAction::LOGIN, $event->user);
    }

    /**
     * Keeps the typed address only when it is one. People paste passwords
     * into the email box, and a log must not hold them.
     */
    public function failed(Failed $event): void
    {
        $typed = $event->credentials[Fortify::username()] ?? null;
        $address = is_string($typed) && filter_var($typed, FILTER_VALIDATE_EMAIL) !== false
            ? mb_substr(mb_strtolower($typed), 0, 255)
            : 'unrecognised';

        if (RateLimiter::attempt('activity-failed:'.request()->ip(), self::FAILED_PER_MINUTE, fn () => true, 60)) {
            app(ActivityLog::class)->record(ActivityAction::LOGIN_FAILED, $address);
        }
    }

    public function logout(Logout $event): void
    {
        $this->record(ActivityAction::LOGOUT, $event->user);
    }

    public function passwordReset(PasswordReset $event): void
    {
        $this->record(ActivityAction::PASSWORD_RESET, $event->user);
    }

    /**
     * Two-factor is switched on at once only when Fortify isn't asked to
     * confirm it first. Otherwise the confirmation is what counts.
     */
    public function twoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        if (! Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')) {
            $this->record(ActivityAction::TWO_FACTOR_ENABLED, $event->user);
        }
    }

    public function twoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record(ActivityAction::TWO_FACTOR_ENABLED, $event->user);
    }

    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record(ActivityAction::TWO_FACTOR_DISABLED, $event->user);
    }

    public function twoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->record(ActivityAction::TWO_FACTOR_FAILED, $event->user);
    }

    private function record(string $action, mixed $user): void
    {
        if ($user instanceof User) {
            app(ActivityLog::class)->record($action, null, [], $user);
        }
    }
}
