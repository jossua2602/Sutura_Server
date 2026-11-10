<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Key/value settings an admin edits (the GCash account shops pay to). */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function read(string $key, ?string $default = null): ?string
    {
        return self::find($key)?->value ?? $default;
    }

    public static function write(string $key, ?string $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** What shop owners see on every "Pay with GCash" box. */
    public static function gcash(): array
    {
        $qr = self::read('gcash_qr_path');

        return [
            'name' => self::read('gcash_name'),
            'number' => self::read('gcash_number'),
            'qr_url' => $qr ? Storage::disk('public')->url($qr) : null,
        ];
    }
}
