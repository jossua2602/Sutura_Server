<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// profile_picture/cover_photo were missing from this list entirely — every
// ProfileController::uploadImage call silently no-op'd on the actual column
// write (Eloquent drops non-fillable attributes on update() without an
// error, same silent-failure class as job_orders.ready_for_pickup_at
// needing forceFill). Verified live: an upload returned success + a correct
// URL, but the user record's profile_picture stayed null. The whole avatar
// upload feature was unreachable for every role, not just newly broken.
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    // Plain properties, not the #[Fillable]/#[Hidden] attributes — those only
    // exist in Laravel 13, and since the downgrade to 12 PHP silently ignored
    // them: every User::create() threw MassAssignmentException and the
    // password hash was serialized into API responses.
    protected $fillable = ['name', 'email', 'contact_email', 'password', 'password_set_at', 'phone', 'suki_tag', 'last_seen_at', 'bio', 'experience', 'education', 'skills', 'social_links', 'creations_gallery', 'profile_picture', 'cover_photo', 'sms_opt_out', 'google_id'];

    protected $hidden = ['password', 'remember_token', 'google_id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password_set_at' => 'datetime',
            // Not fillable on purpose — only Admin\AccountController sets it.
            'suspended_at' => 'datetime',
            'must_change_password' => 'boolean',
            'sms_opt_out' => 'boolean',
            'password' => 'hashed',
            'experience' => 'array',
            'education' => 'array',
            'skills' => 'array',
            'social_links' => 'array',
            'creations_gallery' => 'array',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Shop accounts sign in with an admin-issued login on the shop domain,
     * which receives no mail — send their notifications (approval,
     * credentials, password resets) to the owner's real inbox instead.
     */
    public function routeNotificationForMail(): string
    {
        return $this->contact_email ?: $this->email;
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class, 'owner_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'customer_id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class, 'customer_id');
    }

    public function jobOrders(): HasMany
    {
        return $this->hasMany(JobOrder::class, 'customer_id');
    }

    public function staffProfile()
    {
        return $this->hasOne(StaffProfile::class);
    }
}
