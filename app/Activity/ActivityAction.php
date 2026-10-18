<?php

namespace App\Activity;

/**
 * Every kind of event the activity log records, with the short label the
 * Activity page shows.
 *
 * The code owns this list. A row in ss_activity keeps its action name for
 * good, so rename one only with a migration for the rows already written.
 */
final class ActivityAction
{
    public const LOGIN = 'auth.login';

    public const LOGIN_FAILED = 'auth.failed';

    public const LOGOUT = 'auth.logout';

    public const PASSWORD_RESET = 'auth.password_reset';

    public const PASSWORD_CHANGED = 'auth.password_changed';

    public const SESSIONS_ENDED = 'auth.sessions_ended';

    public const TWO_FACTOR_ENABLED = 'auth.two_factor_enabled';

    public const TWO_FACTOR_DISABLED = 'auth.two_factor_disabled';

    public const TWO_FACTOR_FAILED = 'auth.two_factor_failed';

    public const USER_ROLE_CHANGED = 'user.role_changed';

    public const USER_REMOVED = 'user.removed';

    public const USER_JOINED = 'user.joined';

    public const OWNERSHIP_TRANSFERRED = 'user.ownership_transferred';

    public const USER_SUSPENDED = 'user.suspended';

    public const USER_RESUMED = 'user.resumed';

    public const USER_SIGNED_OUT = 'user.signed_out';

    public const USER_RESET_LINK = 'user.reset_link';

    public const INVITE_SENT = 'invite.sent';

    public const INVITE_RESENT = 'invite.resent';

    public const INVITE_WITHDRAWN = 'invite.withdrawn';

    public const ROLE_CREATED = 'role.created';

    public const ROLE_UPDATED = 'role.updated';

    public const ROLE_DELETED = 'role.deleted';

    public const ROLE_DUPLICATED = 'role.duplicated';

    public const TWO_FACTOR_REQUIREMENT_CHANGED = 'role.two_factor_changed';

    public const ACCOUNT_UPDATED = 'account.updated';

    public const ACCOUNT_EMAIL_REQUESTED = 'account.email_requested';

    public const ACCOUNT_DELETED = 'account.deleted';

    /**
     * The actions by area, each with its label.
     *
     * @return array<string, array<string, string>>
     */
    public static function groups(): array
    {
        return [
            'Sign-in' => [
                self::LOGIN => 'Signed in',
                self::LOGIN_FAILED => 'Failed to sign in',
                self::LOGOUT => 'Signed out',
                self::PASSWORD_RESET => 'Reset a forgotten password',
                self::PASSWORD_CHANGED => 'Changed their password',
                self::SESSIONS_ENDED => 'Signed out their other devices',
                self::TWO_FACTOR_ENABLED => 'Turned on two-factor sign-in',
                self::TWO_FACTOR_DISABLED => 'Turned off two-factor sign-in',
                self::TWO_FACTOR_FAILED => 'Gave a wrong two-factor code',
            ],
            'People' => [
                self::USER_ROLE_CHANGED => 'Changed someone\'s role',
                self::USER_REMOVED => 'Removed someone from the site',
                self::USER_JOINED => 'Joined the site',
                self::OWNERSHIP_TRANSFERRED => 'Handed over ownership of the installation',
                self::USER_SUSPENDED => 'Paused someone\'s access',
                self::USER_RESUMED => 'Resumed someone\'s access',
                self::USER_SIGNED_OUT => 'Signed someone out of their devices',
                self::USER_RESET_LINK => 'Made a password reset link for someone',
                self::INVITE_SENT => 'Invited someone',
                self::INVITE_RESENT => 'Sent an invitation again',
                self::INVITE_WITHDRAWN => 'Withdrew an invitation',
            ],
            'Roles' => [
                self::ROLE_CREATED => 'Created a role',
                self::ROLE_UPDATED => 'Changed a role',
                self::ROLE_DELETED => 'Deleted a role',
                self::ROLE_DUPLICATED => 'Copied a role',
                self::TWO_FACTOR_REQUIREMENT_CHANGED => 'Changed which roles must use two-factor sign-in',
            ],
            'Account' => [
                self::ACCOUNT_UPDATED => 'Changed their profile',
                self::ACCOUNT_EMAIL_REQUESTED => 'Asked to change their email',
                self::ACCOUNT_DELETED => 'Deleted their account',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(array_merge(...array_values(self::groups())));
    }

    /**
     * The label for an action, or the action itself when it is one this
     * version doesn't know, such as a row written by a newer one.
     */
    public static function label(string $action): string
    {
        return array_merge(...array_values(self::groups()))[$action] ?? $action;
    }
}
