<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['first_name', 'middle_name', 'last_name', 'email', 'password', 'role_id', 'location', 'affiliation', 'age', 'gender', 'prc_number', 'street', 'barangay', 'city', 'province', 'country', 'latitude', 'longitude', 'avatar_path'])]
#[Hidden(['password', 'remember_token'])]
#[Table(keyType: 'int', incrementing: true)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The secondary unique ID columns.
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Get the role associated with the user.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the subscriptions associated with the user.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the appeals submitted by the user.
     *
     * @return HasMany<Appeal, $this>
     */
    public function appeals(): HasMany
    {
        return $this->hasMany(Appeal::class);
    }

    /**
     * Get the verification record for the doctor.
     *
     * @return HasOne<DoctorVerification, $this>
     */
    public function verification(): HasOne
    {
        return $this->hasOne(DoctorVerification::class);
    }

    /**
     * Get all doctor verifications for the user.
     *
     * @return HasMany<DoctorVerification, $this>
     */
    public function doctorVerifications(): HasMany
    {
        return $this->hasMany(DoctorVerification::class);
    }

    /**
     * Get the latest doctor verification for the user.
     *
     * @return HasOne<DoctorVerification, $this>
     */
    public function latestDoctorVerification(): HasOne
    {
        return $this->hasOne(DoctorVerification::class)->latestOfMany();
    }

    /**
     * Accessor for user's full name.
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => trim("{$this->first_name} {$this->middle_name} {$this->last_name}"),
        );
    }

    /**
     * Accessor for user's avatar URL.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->avatar_path ? Storage::url($this->avatar_path) : null,
        );
    }
}
