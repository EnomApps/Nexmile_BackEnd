<?php

namespace App\Services\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

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
    /**
     * Store a file and return its path, without touching a record.
     *
     * For the case where the record cannot exist until the file does — a
     * banner created with an empty path and filled in afterwards survives a
     * failed upload as a permanent blank.
     */
    public function store(string $directory, UploadedFile $file): string
    {
        $name = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();

        $path = $file->storeAs($directory, $name, [
            'disk' => $this->disk(),
            'visibility' => 'private',
        ]);

        if ($path === false || $path === '') {
            throw new RuntimeException("Could not store the uploaded file in {$directory}.");
        }

        return $path;
    }

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

        /*
         * storeAs returns false when the write fails — a bucket permission, a
         * full disk, a network blip. Writing that straight to the column left
         * the record pointing at nothing and reporting success, which is how
         * banners reached the app with a null image_url and no error anywhere.
         */
        if ($path === false || $path === '') {
            throw new RuntimeException("Could not store the uploaded file in {$directory}.");
        }

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
     * Delete the stored object without touching a record.
     *
     * For when the record is being deleted too. detach() nulls the column
     * first, which is pointless before a DELETE and outright breaks where the
     * column is NOT NULL — banners.image_path is, because a banner without an
     * image is not a banner.
     */
    public function purge(?string $path): void
    {
        $this->deleteObject($path);
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
     * Validation for a banner, which may animate.
     *
     * Spelled out rather than leaning on Laravel's `image` rule: that rule
     * also admits SVG, which is a document that can carry script, and this is
     * the one upload whose output is rendered full-bleed on every customer's
     * home screen.
     *
     * @return array<int, mixed>
     */
    public static function bannerRules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'sometimes',
            'file',
            'mimes:'.implode(',', config('media.banner_mimes')),
            'max:'.config('media.max_size_kb'),
        ];
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
