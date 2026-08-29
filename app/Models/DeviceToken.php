<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One app install we can reach when it is closed. */
class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'token_hash', 'platform', 'app', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The unique key. The token itself is too long to index directly. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
