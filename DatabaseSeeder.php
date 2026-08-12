<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate([
            'email' => 'admin@example.com',
        ], [
            'name' => 'Registrar Admin',
            'password' => 'password',
            'role' => 'admin',
            'account_status' => 'active',
            'verification_status' => 'approved',
        ]);

        $studentUser = User::query()
            ->where('student_number', '23-1234')
            ->orWhere('email', 'student@example.com')
            ->orWhere('school_email', 'student@isu.edu.ph')
            ->first();

        if (! $studentUser) {
            $studentUser = new User();
        }

        $studentUser->fill([
            'student_number' => '23-1234',
            'name' => 'Sample Student',
            'email' => 'student@example.com',
            'school_email' => 'student@isu.edu.ph',
            'course' => 'BS Information Technology',
            'year_level' => '3rd Year',
            'password' => 'password',
            'role' => 'student',
            'account_status' => 'active',
            'verification_status' => 'approved',
        ]);
        $studentUser->save();

        Student::query()->updateOrCreate([
            'student_number' => '23-1234',
        ], [
            'user_id' => $studentUser->id,
            'full_name' => 'Sample Student',
            'course' => 'BS Information Technology',
            'year_level' => '3rd Year',
            'school_email' => 'student@isu.edu.ph',
            'password_hash' => $studentUser->password,
            'account_status' => 'active',
            'verification_status' => 'approved',
        ]);

        $martStudent = User::query()
            ->where('student_number', '23-5678')
            ->orWhere('email', 'martlanceley@gmail.com')
            ->orWhere('school_email', 'martlanceley@isu.edu.ph')
            ->first();

        if (! $martStudent) {
            $martStudent = new User();
        }

        $martStudent->fill([
            'student_number' => '23-5678',
            'name' => 'Mart Lanceley Babaran',
            'email' => 'martlanceley@gmail.com',
            'school_email' => 'martlanceley@isu.edu.ph',
            'course' => 'BS Information Technology',
            'year_level' => '3rd Year',
            'password' => 'password',
            'role' => 'student',
            'account_status' => 'active',
            'verification_status' => 'approved',
        ]);
        $martStudent->save();

        Student::query()->updateOrCreate([
            'student_number' => '23-5678',
        ], [
            'user_id' => $martStudent->id,
            'full_name' => 'Mart Lanceley Babaran',
            'course' => 'BS Information Technology',
            'year_level' => '3rd Year',
            'school_email' => 'martlanceley@isu.edu.ph',
            'password_hash' => $martStudent->password,
            'account_status' => 'active',
            'verification_status' => 'approved',
        ]);

        User::query()->updateOrCreate([
            'email' => 'registrar@example.com',
        ], [
            'name' => 'Registrar Staff',
            'password' => 'password',
            'role' => 'registrar',
            'account_status' => 'active',
            'verification_status' => 'approved',
        ]);

        User::query()->updateOrCreate([
            'email' => 'cashier@example.com',
        ], [
            'name' => 'Cashier Staff',
            'password' => 'password',
            'role' => 'cashier',
            'account_status' => 'active',
            'verification_status' => 'approved',
        ]);

        foreach (['2023-2024', '2024-2025', '2025-2026'] as $name) {
            AcademicYear::query()->updateOrCreate([
                'name' => $name,
            ], [
                'is_active' => true,
            ]);
        }
    }
}
