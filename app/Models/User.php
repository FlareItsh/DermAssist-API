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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['first_name', 'middle_name', 'last_name', 'email', 'password', 'role_id', 'doctor_id', 'location', 'affiliation', 'age', 'gender', 'prc_number', 'street', 'barangay', 'city', 'province', 'country', 'latitude', 'longitude', 'avatar_path', 'is_doctor_registered', 'registered_by_doctor_id', 'account_status', 'account_action', 'account_action_scheduled_at'])]
#[Hidden(['password', 'remember_token'])]
#[Table(keyType: 'int', incrementing: true)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

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
     * Get the doctor this user (secretary) is associated with.
     *
     * @return BelongsTo<User, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * Get the secretaries associated with this doctor.
     *
     * @return HasMany<User, $this>
     */
    public function secretaries(): HasMany
    {
        return $this->hasMany(User::class, 'doctor_id');
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
     * Get the availability records for the doctor.
     *
     * @return HasMany<DoctorAvailability, $this>
     */
    public function availabilities(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class, 'doctor_id');
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

    /**
     * Get the diagnoses associated with the user.
     *
     * @return HasMany<Diagnosis, $this>
     */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class, 'user_uuid', 'uuid');
    }

    /**
     * Get the active subscription associated with the user.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /**
     * Get the doctor who registered this patient.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredByDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_doctor_id');
    }

    /**
     * Get all doctor-registered patients for this doctor.
     *
     * @return HasMany<User, $this>
     */
    public function doctorRegisteredPatients(): HasMany
    {
        return $this->hasMany(User::class, 'registered_by_doctor_id');
    }

    /**
     * Check whether this user account is currently active.
     */
    public function isActive(): bool
    {
        return $this->account_status === 'active';
    }

    /**
     * Get the resolved active subscription (direct or inherited via clinic).
     */
    public function getActiveSubscription(): ?Subscription
    {
        // 1. Direct active subscription
        $sub = $this->subscriptions()
            ->with('plan.planFeatures')
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->latest('id')
            ->first();

        if ($sub) {
            return $sub;
        }

        if ($this->subscription && $this->subscription->isActive()) {
            return $this->subscription;
        }

        // 2. Inherited Clinic Subscription (for Associate Doctors)
        $clinicMembership = $this->clinicMemberships()
            ->wherePivot('status', 'active')
            ->first();

        if ($clinicMembership && $clinicMembership->owner) {
            return $clinicMembership->owner->getActiveSubscription();
        }

        return null;
    }

    /**
     * Check whether this user has an active doctor subscription.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->getActiveSubscription() !== null;
    }

    /**
     * Check whether the user's active subscription plan includes a specific feature flag.
     */
    public function canAccessFeature(string $featureKey): bool
    {
        $subscription = $this->getActiveSubscription();
        if (! $subscription || ! $subscription->plan) {
            return false;
        }

        return $subscription->plan->hasFeature($featureKey);
    }

    /**
     * Check whether the doctor can execute AI scans based on active plan features.
     */
    public function canExecuteScan(): bool
    {
        return $this->canAccessFeature('can_execute_scan');
    }

    /**
     * Check whether the doctor is eligible to appear in patient scan recommendations.
     */
    public function canBeRecommended(): bool
    {
        return $this->canAccessFeature('show_in_recommendation');
    }

    /**
     * Get the clinics owned by this doctor.
     *
     * @return HasMany<Clinic, $this>
     */
    public function ownedClinics(): HasMany
    {
        return $this->hasMany(Clinic::class, 'owner_doctor_id');
    }

    /**
     * Get the clinic memberships where this doctor is an associate or member.
     *
     * @return BelongsToMany<Clinic, $this>
     */
    public function clinicMemberships(): BelongsToMany
    {
        return $this->belongsToMany(Clinic::class, 'clinic_doctors', 'doctor_user_id', 'clinic_id')
            ->withPivot('role', 'status')
            ->withTimestamps();
    }

    /**
     * Get the maximum allowed clinics for this doctor's subscription plan.
     * Returns null if unlimited, or integer limit (default 1).
     */
    public function getMaxClinics(): ?int
    {
        $subscription = $this->getActiveSubscription();
        if (! $subscription || ! $subscription->plan) {
            return 1;
        }

        return $subscription->plan->max_clinics ?? 1;
    }

    /**
     * Check whether the doctor is eligible to have secretary accounts.
     */
    public function canHaveSecretary(): bool
    {
        $subscription = $this->getActiveSubscription();
        if (! $subscription || ! $subscription->plan) {
            return false;
        }

        $plan = $subscription->plan;

        // Must either have can_have_secretary feature or max_secretaries > 0 (or null for unlimited)
        return $this->canAccessFeature('can_have_secretary') || ($plan->max_secretaries === null || $plan->max_secretaries > 0);
    }

    /**
     * Get the maximum allowed secretaries for this doctor's subscription plan.
     * Returns null if unlimited, or integer limit.
     */
    public function getMaxSecretaries(): ?int
    {
        $subscription = $this->getActiveSubscription();
        if (! $subscription || ! $subscription->plan) {
            return 0;
        }

        return $subscription->plan->max_secretaries;
    }

    /**
     * Get the maximum allowed doctors for this doctor's subscription plan.
     * Returns null if unlimited, or integer limit (default 1).
     */
    public function getMaxDoctors(): ?int
    {
        $subscription = $this->getActiveSubscription();
        if (! $subscription || ! $subscription->plan) {
            return 1;
        }

        return $subscription->plan->max_doctors;
    }

    /**
     * Check whether the doctor can add associate doctors.
     */
    public function canAddDoctor(): bool
    {
        $maxDoctors = $this->getMaxDoctors();

        return $maxDoctors === null || $maxDoctors > 1;
    }

    /**
     * Get the doctor seat quota statistics.
     *
     * @return array{max_doctors: ?int, used_seats: int, available_seats: ?int, can_add: bool}
     */
    public function getDoctorSeatUsage(): array
    {
        $maxDoctors = $this->getMaxDoctors();

        // Count distinct active associate doctors across all clinics owned by this doctor
        $distinctAssociatesCount = DB::table('clinic_doctors')
            ->join('clinics', 'clinic_doctors.clinic_id', '=', 'clinics.id')
            ->where('clinics.owner_doctor_id', $this->id)
            ->where('clinic_doctors.status', 'active')
            ->distinct('clinic_doctors.doctor_user_id')
            ->count('clinic_doctors.doctor_user_id');

        // Total used seats = 1 (Owner) + distinct associates
        $usedSeats = 1 + $distinctAssociatesCount;
        $availableSeats = $maxDoctors !== null ? max(0, $maxDoctors - $usedSeats) : null;
        $canAdd = $maxDoctors === null || $availableSeats > 0;

        return [
            'max_doctors' => $maxDoctors,
            'used_seats' => $usedSeats,
            'available_seats' => $availableSeats,
            'can_add' => $canAdd,
        ];
    }
}
