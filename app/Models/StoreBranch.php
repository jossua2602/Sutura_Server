<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreBranch extends Model
{
    protected $fillable = [
        'store_id', 'name', 'slug', 'address', 'landmark', 'landmark_image_url', 'barangay', 'city', 'district', 'latitude', 'longitude', 'contact_number', 'is_main', 'operating_hours', 'status', 'guide_image_url',
        'verification_status', 'verified_at', 'verified_by', 'verification_note',
    ];

    protected $casts = ['verified_at' => 'datetime'];

    /** What the public may see: switched on by the shop AND location-checked by the System Admin. */
    public function scopeLive($query)
    {
        return $query->where('status', 'active')->where('verification_status', 'verified');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function staffProfiles()
    {
        return $this->hasMany(StaffProfile::class);
    }

    public function manager()
    {
        return $this->hasOne(StaffProfile::class, 'store_branch_id')->where('is_branch_manager', true);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function jobOrders()
    {
        return $this->hasMany(JobOrder::class);
    }
}
