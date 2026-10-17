<?php

namespace Tests\Feature\Activity;

use App\Access\Ability;
use App\Activity\ActivityAction;
use App\Activity\ActivityDescriber;
use App\Models\Activity;
use Tests\TestCase;

class ActivityDescriberTest extends TestCase
{
    /**
     * @param  array<string, mixed>|null  $with
     * @return list<string>
     */
    private function details(string $action, ?array $with): array
    {
        return ActivityDescriber::details(new Activity(['action' => $action, 'properties' => $with]));
    }

    public function test_a_role_change_names_both_roles()
    {
        $this->assertSame(['Editor to Viewer'], $this->details(ActivityAction::USER_ROLE_CHANGED, ['from' => 'Editor', 'to' => 'Viewer']));
    }

    public function test_removals_joins_and_invitations_name_the_role()
    {
        $this->assertSame(['was Editor'], $this->details(ActivityAction::USER_REMOVED, ['role' => 'Editor']));
        $this->assertSame(['as Editor'], $this->details(ActivityAction::USER_JOINED, ['role' => 'Editor']));
        $this->assertSame(['as Viewer'], $this->details(ActivityAction::INVITE_SENT, ['role' => 'Viewer']));
        $this->assertSame(['as Viewer'], $this->details(ActivityAction::INVITE_RESENT, ['role' => 'Viewer']));
        $this->assertSame(['as Viewer'], $this->details(ActivityAction::INVITE_WITHDRAWN, ['role' => 'Viewer']));
    }

    public function test_a_new_role_counts_its_abilities()
    {
        $this->assertSame(['with no abilities'], $this->details(ActivityAction::ROLE_CREATED, ['abilities' => []]));
        $this->assertSame(['with 1 ability'], $this->details(ActivityAction::ROLE_CREATED, ['abilities' => [Ability::ENTRIES_VIEW]]));
        $this->assertSame(['with 2 abilities'], $this->details(ActivityAction::ROLE_CREATED, ['abilities' => [Ability::ENTRIES_VIEW, Ability::MEDIA_VIEW]]));
    }

    public function test_an_edited_role_says_what_was_renamed_added_and_removed_in_words()
    {
        $lines = $this->details(ActivityAction::ROLE_UPDATED, [
            'renamed_from' => 'Proofreader',
            'added' => [Ability::ENTRIES_UPDATE, Ability::ENTRIES_TRASH, Ability::MEDIA_VIEW],
            'removed' => [Ability::ENTRIES_DELETE],
        ]);

        $this->assertSame([
            'renamed from Proofreader',
            'added Edit entries, Move entries to the trash and See and pick files',
            'removed Delete entries for good',
        ], $lines);
    }

    public function test_an_ability_this_version_does_not_know_is_shown_as_it_was_stored()
    {
        $this->assertSame(['added forms.view'], $this->details(ActivityAction::ROLE_UPDATED, ['added' => ['forms.view']]));
    }

    public function test_a_two_factor_change_names_the_roles_now_held_to_it()
    {
        $this->assertSame(['now required of Administrator and Editor'], $this->details(ActivityAction::TWO_FACTOR_REQUIREMENT_CHANGED, ['roles' => ['Administrator', 'Editor']]));
        $this->assertSame(['no role has to use it now'], $this->details(ActivityAction::TWO_FACTOR_REQUIREMENT_CHANGED, ['roles' => []]));
    }

    public function test_a_copied_role_names_the_original()
    {
        $this->assertSame(['from Editor'], $this->details(ActivityAction::ROLE_DUPLICATED, ['from' => 'Editor']));
    }

    public function test_a_profile_change_lists_the_fields_and_the_addresses()
    {
        $this->assertSame(['name'], $this->details(ActivityAction::ACCOUNT_UPDATED, ['changed' => ['name']]));
        $this->assertSame(
            ['name and email', 'old@example.com to new@example.com'],
            $this->details(ActivityAction::ACCOUNT_UPDATED, ['changed' => ['name', 'email'], 'email_from' => 'old@example.com', 'email_to' => 'new@example.com']),
        );
    }

    public function test_rows_with_nothing_to_add_or_odd_data_give_no_lines()
    {
        $this->assertSame([], $this->details(ActivityAction::LOGIN, null));
        $this->assertSame([], $this->details(ActivityAction::USER_ROLE_CHANGED, ['from' => 'Editor']));
        $this->assertSame([], $this->details(ActivityAction::USER_REMOVED, ['role' => ['not', 'text']]));
        $this->assertSame([], $this->details('auth.something_new', ['anything' => 1]));
    }
}
