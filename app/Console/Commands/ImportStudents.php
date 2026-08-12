<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ImportStudents extends Command
{
    protected $signature = 'registrar:import-students {file : CSV file exported from the official student database}';

    protected $description = 'Import or synchronize pre-registered student accounts from the official student database CSV.';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('The import file does not exist or is not readable.');

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');

        if (! $handle) {
            $this->error('Unable to open the import file.');

            return self::FAILURE;
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers)) {
            fclose($handle);
            $this->error('The import file is empty.');

            return self::FAILURE;
        }

        $headers = array_map(fn ($header) => trim(strtolower((string) $header)), $headers);
        $required = ['student_number', 'full_name', 'course', 'year_level', 'school_email', 'account_status'];
        $missing = array_diff($required, $headers);

        if ($missing !== []) {
            fclose($handle);
            $this->error('Missing required columns: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $imported = 0;
        $skipped = 0;
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $data = array_combine($headers, array_pad($row, count($headers), null));

            if (! is_array($data)) {
                $skipped++;
                $this->warn("Row {$rowNumber} skipped: invalid column count.");

                continue;
            }

            $data = array_map(fn ($value) => trim((string) $value), $data);
            $password = $data['password'] ?? bin2hex(random_bytes(6));

            $validator = Validator::make($data, [
                'student_number' => ['required', 'string', 'max:50'],
                'full_name' => ['required', 'string', 'max:255'],
                'course' => ['required', 'string', 'max:255'],
                'year_level' => ['required', 'string', 'max:30'],
                'school_email' => ['required', 'email', 'max:255'],
                'account_status' => ['required', Rule::in([User::ACCOUNT_ACTIVE, User::ACCOUNT_INACTIVE])],
            ]);

            if ($validator->fails()) {
                $skipped++;
                $this->warn("Row {$rowNumber} skipped: ".$validator->errors()->first());

                continue;
            }

            DB::transaction(function () use ($data, $password): void {
                $user = User::query()
                    ->where('student_number', $data['student_number'])
                    ->orWhere('email', $data['school_email'])
                    ->orWhere('school_email', $data['school_email'])
                    ->first();

                if (! $user) {
                    $user = new User();
                }

                $user->fill([
                    'student_number' => $data['student_number'],
                    'name' => $data['full_name'],
                    'email' => $data['school_email'],
                    'school_email' => $data['school_email'],
                    'course' => $data['course'],
                    'year_level' => $data['year_level'],
                    'password' => password_hash($password, PASSWORD_BCRYPT),
                    'role' => User::ROLE_STUDENT,
                    'account_status' => $data['account_status'],
                    'verification_status' => User::VERIFICATION_UNSUBMITTED,
                ]);
                $user->save();

                Student::query()->updateOrCreate([
                    'student_number' => $data['student_number'],
                ], [
                    'user_id' => $user->id,
                    'full_name' => $data['full_name'],
                    'course' => $data['course'],
                    'year_level' => $data['year_level'],
                    'school_email' => $data['school_email'],
                    'password_hash' => $user->password,
                    'account_status' => $data['account_status'],
                    'verification_status' => $user->verification_status,
                ]);
            });

            $imported++;
        }

        fclose($handle);

        $this->info("Imported {$imported} student record(s). Skipped {$skipped} row(s).");

        return self::SUCCESS;
    }
}
