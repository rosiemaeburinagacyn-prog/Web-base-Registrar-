-- Run this in phpMyAdmin if demo users were imported before 2026-06-29 22:45.
-- It resets every demo account password to: password

UPDATE `users`
SET `password` = '$2y$12$jSuEE03jIJqHF4C8UnXUnOvhJI4dAY.jwznwEBtLZbTc6OI2lixia',
    `updated_at` = NOW()
WHERE `email` IN (
    'admin@example.com',
    'student@example.com',
    'registrar@example.com',
    'cashier@example.com',
    'martlanceley@gmail.com'
)
OR `student_number` IN ('23-1234', '23-5678');

UPDATE `students`
SET `password_hash` = '$2y$12$jSuEE03jIJqHF4C8UnXUnOvhJI4dAY.jwznwEBtLZbTc6OI2lixia',
    `updated_at` = NOW()
WHERE `student_number` IN ('23-1234', '23-5678');
