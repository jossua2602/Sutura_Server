<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreApplication extends Model
{
    /** Document fields an admin can open, keyed by the URL segment. */
    public const DOCUMENTS = [
        'landmark' => 'landmark_image_path',
        'dti' => 'dti_registration_path',
        'tin' => 'tin_id_path',
        'barangay' => 'brgy_clearance_path',
        'government-id' => 'government_id_path',
        'receipt' => 'payment_receipt_path',
    ];

    protected $fillable = [
        'user_id', 'store_id', 'first_name', 'middle_name', 'last_name', 'suffix',
        'birthday', 'contact_number', 'requested_plan_id', 'billing_cycle', 'quoted_price',
        'payment_method', 'payment_receipt_path', 'landmark_image_path', 'dti_registration_path',
        'tin_id_path', 'brgy_clearance_path', 'government_id_path', 'government_id_type',
        'business_permit_paths', 'reviewed_by', 'reviewed_at',
        'review_state', 'change_request_items', 'change_request_note', 'change_requested_at', 'change_requested_by',
        'resubmitted_at', 'update_token_hash', 'update_token_expires_at', 'change_history',
        'previous_documents',
    ];

    // Raw storage paths never leave the server — admins get documents
    // through the authenticated stream endpoint instead. The update-link
    // hash is a credential.
    protected $hidden = [
        'payment_receipt_path', 'landmark_image_path', 'dti_registration_path', 'tin_id_path',
        'brgy_clearance_path', 'government_id_path', 'business_permit_paths', 'update_token_hash',
        'previous_documents',
    ];

    protected $casts = [
        'birthday' => 'date',
        'quoted_price' => 'decimal:2',
        'business_permit_paths' => 'array',
        'reviewed_at' => 'datetime',
        'change_request_items' => 'array',
        'change_requested_at' => 'datetime',
        'resubmitted_at' => 'datetime',
        'update_token_expires_at' => 'datetime',
        'change_history' => 'array',
        'previous_documents' => 'array',
    ];

    /**
     * What an admin can ask the owner to redo ("Request Changes"), and the
     * owner's update page edits exactly these. Document keys match DOCUMENTS.
     */
    public const CHANGE_ITEMS = [
        'owner' => 'Owner details (name, birthday, contact)',
        'shop' => 'Shop details (name, address, map pin)',
        'payment' => 'Payment receipt',
        'landmark' => 'Shop front photo',
        'dti' => 'DTI registration',
        'tin' => 'TIN / BIR 2303',
        'barangay' => 'Barangay clearance',
        'government-id' => 'Government ID',
        'permits' => 'Business permits',
    ];

    /** How long the emailed update link works. */
    public const UPDATE_LINK_DAYS = 14;

    /** @return array<int, array{key: string, label: string}> */
    public function requestedItems(): array
    {
        return collect($this->change_request_items ?? [])
            ->filter(fn ($key) => isset(self::CHANGE_ITEMS[$key]))
            ->map(fn ($key) => ['key' => $key, 'label' => self::CHANGE_ITEMS[$key]])
            ->values()
            ->all();
    }

    /** The application an emailed update link belongs to, while it still works. */
    public static function forUpdateToken(?string $token): ?self
    {
        if (! $token || strlen($token) > 128) {
            return null;
        }

        return self::where('update_token_hash', hash('sha256', $token))
            ->where('review_state', 'changes_requested')
            ->where('update_token_expires_at', '>', now())
            ->whereHas('store', fn ($q) => $q->where('status', 'pending'))
            ->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function requestedPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'requested_plan_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function changeRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'change_requested_by');
    }

    /**
     * Which documents were actually uploaded, for the admin checklist. Each
     * entry says which change-request item covers it, when this version was
     * uploaded, and the version it replaced (if the owner re-uploaded it).
     */
    public function documentList(): array
    {
        $docs = [];
        foreach (self::DOCUMENTS as $key => $column) {
            if ($this->{$column}) {
                $docs[] = $this->documentEntry($key, $this->{$column}, $key === 'receipt' ? 'payment' : $key);
            }
        }
        foreach ($this->business_permit_paths ?? [] as $i => $path) {
            $docs[] = $this->documentEntry('permit-'.$i, $path, 'permits');
        }

        return $docs;
    }

    public function pathFor(string $key): ?string
    {
        if (isset(self::DOCUMENTS[$key])) {
            return $this->{self::DOCUMENTS[$key]};
        }
        if (preg_match('/^permit-(\d+)$/', $key, $m)) {
            return ($this->business_permit_paths ?? [])[(int) $m[1]] ?? null;
        }

        return null;
    }

    /** The file a re-upload replaced, kept for the admin's Before / After view. */
    public function previousPathFor(string $key): ?string
    {
        return ($this->previous_documents ?? [])[$key]['path'] ?? null;
    }

    private function documentEntry(string $key, string $path, string $item): array
    {
        $previous = ($this->previous_documents ?? [])[$key] ?? null;

        return [
            'key' => $key,
            'item' => $item,
            'type' => $this->mimeFor($path),
            // A replaced file was uploaded when the owner resubmitted;
            // everything else dates from the original application.
            'uploaded_at' => $previous['replaced_at'] ?? $this->created_at?->toISOString(),
            'previous' => $previous ? [
                'type' => $this->mimeFor($previous['path']),
                'uploaded_at' => $previous['uploaded_at'] ?? null,
            ] : null,
        ];
    }

    public function ownerFullName(): string
    {
        return trim(preg_replace('/\s+/', ' ', "{$this->first_name} {$this->middle_name} {$this->last_name} {$this->suffix}"));
    }

    private function mimeFor(string $path): string
    {
        return str_ends_with(strtolower($path), '.pdf') ? 'pdf' : 'image';
    }
}
