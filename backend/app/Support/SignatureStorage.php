<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Decodes a base64 data-URI signature (from the React signature pad) and
 * stores it as a PNG on the configured file disk.
 */
class SignatureStorage
{
    public static function storeBase64(string $dataUri, int|string $transferId): string
    {
        // Accepts "data:image/png;base64,xxxx" or a raw base64 string.
        if (str_contains($dataUri, ',')) {
            [$meta, $payload] = explode(',', $dataUri, 2);
        } else {
            $payload = $dataUri;
        }

        $binary = base64_decode($payload, true);
        if ($binary === false) {
            throw new InvalidArgumentException('Invalid signature payload.');
        }

        $disk = config('filesystems.default');
        $path = "documents/signatures/{$transferId}/".Str::uuid().'.png';
        Storage::disk($disk)->put($path, $binary);

        return $path;
    }

    /** Store an uploaded photo (stock-count evidence) and return its path. */
    public static function storeUpload(\Illuminate\Http\UploadedFile $file, string $folder): string
    {
        $disk = config('filesystems.default');

        return $file->store($folder, $disk);
    }
}
