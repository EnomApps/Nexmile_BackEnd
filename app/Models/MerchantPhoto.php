<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One slide in a restaurant's photo carousel. */
class MerchantPhoto extends Model
{
    /*
     * image_path is written by ImageService after the object is stored, never
     * taken from request input — a mass-assignable path column is a way to
     * point a record at anything in the bucket.
     */
    protected $fillable = ['merchant_id', 'caption', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
