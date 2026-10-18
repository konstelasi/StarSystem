<?php

namespace App\Access;

/**
 * Every ability a role can hold, one per action, so a role can allow some
 * actions on a thing and refuse others.
 *
 * The code owns this list and the ss_role_abilities table owns who holds
 * which. Adding an action means adding it here, checking it on its route,
 * and a migration that grants it to the built-in Editor or Viewer if they
 * should have it. The built-in Administrator holds every ability that exists
 * and needs no migration.
 *
 * Modules add abilities of their own through their manifest. See
 * AbilityRegistry for the list that includes them.
 */
final class Ability
{
    public const ENTRIES_VIEW = 'entries.view';

    public const ENTRIES_CREATE = 'entries.create';

    public const ENTRIES_UPDATE = 'entries.update';

    public const ENTRIES_UPDATE_OWN = 'entries.update_own';

    public const ENTRIES_TRASH = 'entries.trash';

    public const ENTRIES_TRASH_OWN = 'entries.trash_own';

    public const ENTRIES_RESTORE = 'entries.restore';

    public const ENTRIES_RESTORE_OWN = 'entries.restore_own';

    public const ENTRIES_DELETE = 'entries.delete';

    public const ENTRIES_DELETE_OWN = 'entries.delete_own';

    public const MODELS_VIEW = 'models.view';

    public const MODELS_CREATE = 'models.create';

    public const MODELS_UPDATE = 'models.update';

    public const MODELS_DELETE = 'models.delete';

    public const MODELS_IMPORT = 'models.import';

    public const MODELS_EXPORT = 'models.export';

    public const MEDIA_VIEW = 'media.view';

    public const MEDIA_UPLOAD = 'media.upload';

    public const MEDIA_UPDATE = 'media.update';

    public const MEDIA_DELETE = 'media.delete';

    public const USERS_VIEW = 'users.view';

    public const USERS_INVITE = 'users.invite';

    public const USERS_UPDATE = 'users.update';

    public const USERS_REMOVE = 'users.remove';

    public const USERS_SUSPEND = 'users.suspend';

    public const USERS_SIGN_OUT = 'users.sign_out';

    public const USERS_RESET_PASSWORD = 'users.reset_password';

    public const ROLES_VIEW = 'roles.view';

    public const ROLES_CREATE = 'roles.create';

    public const ROLES_UPDATE = 'roles.update';

    public const ROLES_DELETE = 'roles.delete';

    public const HEALTH_VIEW = 'health.view';

    public const ACTIVITY_VIEW = 'activity.view';

    /**
     * The form of an ability that covers only the entries the account
     * created, for each ability that has one. Holding the full ability covers
     * every entry, so it also covers its own form. Holding only the own form
     * covers an entry only when its author is that account.
     */
    public const OWN = [
        self::ENTRIES_UPDATE => self::ENTRIES_UPDATE_OWN,
        self::ENTRIES_TRASH => self::ENTRIES_TRASH_OWN,
        self::ENTRIES_RESTORE => self::ENTRIES_RESTORE_OWN,
        self::ENTRIES_DELETE => self::ENTRIES_DELETE_OWN,
    ];

    /**
     * The abilities by area, each with the short label the roles page shows.
     *
     * @return array<string, array<string, string>>
     */
    public static function groups(): array
    {
        return [
            'Entries' => [
                self::ENTRIES_VIEW => 'See entries and open them',
                self::ENTRIES_CREATE => 'Create entries',
                self::ENTRIES_UPDATE => 'Edit entries',
                self::ENTRIES_UPDATE_OWN => 'Edit entries they created',
                self::ENTRIES_TRASH => 'Move entries to the trash',
                self::ENTRIES_TRASH_OWN => 'Move entries they created to the trash',
                self::ENTRIES_RESTORE => 'Restore entries from the trash',
                self::ENTRIES_RESTORE_OWN => 'Restore entries they created',
                self::ENTRIES_DELETE => 'Delete entries for good',
                self::ENTRIES_DELETE_OWN => 'Delete entries they created for good',
            ],
            'Models' => [
                self::MODELS_VIEW => 'See the list of models',
                self::MODELS_CREATE => 'Create and duplicate models',
                self::MODELS_UPDATE => 'Change a model in the builder',
                self::MODELS_DELETE => 'Delete models',
                self::MODELS_IMPORT => 'Import models',
                self::MODELS_EXPORT => 'Export models',
            ],
            'Media' => [
                self::MEDIA_VIEW => 'See and pick files',
                self::MEDIA_UPLOAD => 'Upload files',
                self::MEDIA_UPDATE => 'Rename and move files',
                self::MEDIA_DELETE => 'Delete files',
            ],
            'Users' => [
                self::USERS_VIEW => 'See who has access',
                self::USERS_INVITE => 'Invite people',
                self::USERS_UPDATE => 'Change what role someone holds',
                self::USERS_REMOVE => 'Remove people from the site',
                self::USERS_SUSPEND => 'Pause and resume people',
                self::USERS_SIGN_OUT => 'Sign people out of their devices',
                self::USERS_RESET_PASSWORD => 'Send people a password reset link',
            ],
            'Roles' => [
                self::ROLES_VIEW => 'See the roles',
                self::ROLES_CREATE => 'Create roles',
                self::ROLES_UPDATE => 'Change what a role can do',
                self::ROLES_DELETE => 'Delete roles',
            ],
            'System' => [
                self::HEALTH_VIEW => 'See the Health page',
                self::ACTIVITY_VIEW => 'See the activity log',
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
     * The first word of every ability, such as "entries" and "users", which a
     * module's own abilities can't borrow.
     *
     * @return list<string>
     */
    public static function areas(): array
    {
        return array_values(array_unique(array_map(fn (string $ability) => explode('.', $ability)[0], self::all())));
    }

    public static function exists(string $ability): bool
    {
        return in_array($ability, self::all(), true);
    }
}
