<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetOtpNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $otp)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('ISU Registrar Password Reset OTP')
            ->greeting('Password reset request')
            ->line('Use this one-time password to reset your ISU Registrar account password:')
            ->line($this->otp)
            ->line('This OTP expires in 10 minutes. If you did not request it, ignore this email.');
    }
}
