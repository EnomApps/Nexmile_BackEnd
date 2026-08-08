<?php

namespace App\Services\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Photos that customers see: dish images, storefront logos and banners.
 *
 * Stored on a private disk and read through signed URLs, the same as KYC
 * documents — see config/media.php for why.
 */
class ImageService
{
    /**
     * Attach a photo to a model column, replacing whatever was there.
     *
     * The old object is deleted only after the new path is committed. A failed
     * write leaves the record with its previous photo rather than none.
     */
    public function attach(Model $model, string $column, string $directory, UploadedFile $file): Model
    {
        $previous = $model->getAttribute($column);

        // A random name, not the uploaded one: user-supplied filenames can
        // carry path separators and a second extension.
        $name = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();

        $path = $file->storeAs($directory, $name, [
            'disk' => $this->disk(),
            'visibility' => 'private',
        ]);

        $model->forceFill([$column => $path])->save();

        $this->deleteObject($previous);

        return $model;
    }

    public function detach(Model $model, string $column): Model
    {
        $previous = $model->getAttribute($column);

        $model->forceFill([$column => null])->save();

        $this->deleteObject($previous);

        return $model;
    }

    /**
     * A signed, expiring link. Null when there is no photo — the apps show a
     * placeholder rather than a broken image.
     */
    public function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk($this->disk())->temporaryUrl(
            $path,
            now()->addMinutes(config('media.url_ttl_minutes')),
        );
    }

    /**
     * Validation for an uploaded image, shared by every caller so the API and
     * the portal cannot accept different files.
     *
     * @return array<int, mixed>
     */
    public static function rules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'sometimes',
            'file',
            'mimes:'.implode(',', config('media.mimes')),
            'max:'.config('media.max_size_kb'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field = 'image'): array
    {
        $mb = round(config('media.max_size_kb') / 1024, 1);

        return [
            "{$field}.mimes" => 'Upload a JPG, PNG or WebP photo.',
            "{$field}.max" => "Photos must be under {$mb} MB.",
            // PHP discards an oversized upload before the max rule can run.
            "{$field}.uploaded" => "That photo is too large to upload. Keep it under {$mb} MB.",
        ];
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
        return config('media.disk');
    }
}
