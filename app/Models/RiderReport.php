<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a customer said about a rider on one order. */
class RiderReport extends Model
{
    public const PENDING = 'pending';

    public const UPHELD = 'upheld';

    public const DISMISSED = 'dismissed';

    protected $fillable = [
        'order_id', 'rider_id', 'reported_by_user_id',
        'category', 'description',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /** Safety and money first — those are not service quality complaints. */
    public function isSerious(): bool
    {
        return (bool) (config("conduct.report_categories.{$this->category}.serious") ?? false);
    }

    public function label(): string
    {
        return config("conduct.report_categories.{$this->category}.label") ?? $this->category;
    }
}
