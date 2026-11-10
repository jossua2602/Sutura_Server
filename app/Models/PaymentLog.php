<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step in a platform payment's timeline (Admin → Payments → Review). */
class PaymentLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['payment_id', 'user_id', 'action', 'note', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
