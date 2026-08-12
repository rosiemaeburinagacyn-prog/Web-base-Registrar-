<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_STUDENT = 'student';
    public const ROLE_REGISTRAR = 'registrar';
    public const ROLE_CASHIER = 'cashier';
    public const ROLE_ADMIN = 'admin';

    public const ACCOUNT_ACTIVE = 'active';
    public const ACCOUNT_INACTIVE = 'inactive';

    public const VERIFICATION_UNSUBMITTED = 'unsubmitted';
    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_APPROVED = 'approved';
    public const VERIFICATION_REJECTED = 'rejected';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'student_number',
        'course',
        'year_level',
        'school_email',
        'password',
        'role',
        'account_status',
        'verification_status',
        'school_id_path',
        'selfie_id_path',
        'verification_submitted_at',
        'verification_reviewed_at',
        'verification_reviewed_by',
        'verification_note',
        'password_reset_otp_hash',
        'password_reset_otp_expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'password_reset_otp_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verification_submitted_at' => 'datetime',
            'verification_reviewed_at' => 'datetime',
            'password_reset_otp_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isStudent(): bool
    {
        return $this->role === self::ROLE_STUDENT;
    }

    public function isRegistrar(): bool
    {
        return $this->role === self::ROLE_REGISTRAR;
    }

    public function isCashier(): bool
    {
        return $this->role === self::ROLE_CASHIER;
    }

    public function isActive(): bool
    {
        return ($this->account_status ?: self::ACCOUNT_ACTIVE) === self::ACCOUNT_ACTIVE;
    }

    public function isVerificationApproved(): bool
    {
        return $this->verification_status === self::VERIFICATION_APPROVED;
    }

    public function canRequestDocuments(): bool
    {
        return $this->isStudent() && $this->isActive() && $this->isVerificationApproved();
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->school_email ?: $this->email;
    }
}
