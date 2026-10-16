<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Every user starts as an administrator of the default site, so a test
     * that only needs someone signed in isn't turned away. A test about
     * access gives the user a different membership, or none.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            DB::table('ss_site_users')->insert([
                'tenant_id' => config('starsystem.default_site'),
                'user_id' => $user->id,
                'role_id' => Role::builtIn(Role::ADMINISTRATOR)->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Indicate that the user runs updates and modules for the whole install.
     */
    public function installOwner(): static
    {
        return $this->state(fn (array $attributes) => ['is_install_owner' => true]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
