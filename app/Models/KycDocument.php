<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class KycDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'documentable_type', 'documentable_id', 'type', 'status',
        'disk', 'path', 'original_name', 'mime_type', 'size_bytes',
        'rejection_reason', 'reviewed_by_user_id', 'reviewed_at',
    ];

    /**
     * The storage path is never serialised. Exposing it would let anyone with
     * the bucket name construct a direct object URL, bypassing the signed-link
     * expiry entirely.
     */
    protected $hidden = ['path', 'disk'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'size_bytes' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * A short-lived signed download link.
     *
     * Local disks cannot sign URLs, so fall back to a plain URL there — that
     * path is only ever hit in development and tests.
     */
    public function temporaryUrl(): ?string
    {
        $disk = Storage::disk($this->disk);
        $expiry = now()->addMinutes((int) config('kyc.link_ttl_minutes'));

        try {
            return $disk->temporaryUrl($this->path, $expiry);
        } catch (\Throwable) {
            return $disk->exists($this->path) ? $disk->url($this->path) : null;
        }
    }

    public function isApproved(): bool
    {
        return $this->status === DocumentStatus::Approved;
    }

    /** An approved document is locked; only pending or rejected can be replaced. */
    public function canBeReplaced(): bool
    {
        return $this->status !== DocumentStatus::Approved;
    }
}
