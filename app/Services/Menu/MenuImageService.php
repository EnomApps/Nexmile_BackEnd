<?php

namespace App\Services\Menu;

use App\Models\MenuItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dish photos for menu items (EP3).
 *
 * Stored on a private disk and read through signed URLs, the same as KYC
 * documents — see config/menu.php for why.
 */
class MenuImageService
{
    /**
     * Attach a photo, replacing whatever the item had before.
     *
     * The old object is deleted only after the new path is committed. A failed
     * write leaves the item with its previous photo rather than none.
     */
    public function attach(MenuItem $item, UploadedFile $file): MenuItem
    {
        $previous = $item->image_path;

        // A random name, not the uploaded one: user-supplied filenames can
        // carry path separators and a second extension.
        $name = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();

        $path = $file->storeAs(
            sprintf('menu/%d', $item->merchant_id),
            $name,
            ['disk' => $this->disk(), 'visibility' => 'private'],
        );

        $item->update(['image_path' => $path]);

        $this->deleteObject($previous);

        return $item;
    }

    public function detach(MenuItem $item): MenuItem
    {
        $previous = $item->image_path;

        $item->update(['image_path' => null]);

        $this->deleteObject($previous);

        return $item;
    }

    /**
     * A signed, expiring link. Null when the item has no photo — the apps show
     * a placeholder rather than a broken image.
     */
    public function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk($this->disk())->temporaryUrl(
            $path,
            now()->addMinutes(config('menu.image_url_ttl_minutes')),
        );
    }

    /**
     * Deleting the object is housekeeping, not part of the operation. S3 being
     * briefly unavailable should not fail the merchant's edit and leave the
     * database disagreeing with what they just saw.
     */
    protected function deleteObject(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        rescue(fn () => Storage::disk($this->disk())->delete($path), report: true);
    }

    protected function disk(): string
    {
        return config('menu.image_disk');
    }
}
