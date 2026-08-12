<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
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
        $studentNumber = fake()->unique()->numerify('##-####');
        $email = fake()->unique()->safeEmail();

        return [
            'name' => fake()->name(),
            'email' => $email,
            'email_verified_at' => now(),
            'student_number' => $studentNumber,
            'course' => 'BS Information Technology',
            'year_level' => '3rd Year',
            'school_email' => $email,
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'student',
            'account_status' => 'active',
            'verification_status' => 'approved',
            'remember_token' => Str::random(10),
        ];
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

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
            'student_number' => null,
            'course' => null,
            'year_level' => null,
            'school_email' => null,
            'verification_status' => 'approved',
        ]);
    }

    public function registrar(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'registrar',
            'student_number' => null,
            'course' => null,
            'year_level' => null,
            'school_email' => null,
            'verification_status' => 'approved',
        ]);
    }

    public function cashier(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'cashier',
            'student_number' => null,
            'course' => null,
            'year_level' => null,
            'school_email' => null,
            'verification_status' => 'approved',
        ]);
    }
}
