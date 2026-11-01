<?php

namespace App\Services;

use App\Models\TicketAttachment;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;

class DocumentationPhotoStore
{
    public const MAX_PHOTO_KB = 5120;

    public const MAX_VIDEO_KB = 51200;

    public const MESSAGES = [
        'photos.max' => 'Maksimal 5 foto/video per catatan',
        'photos.*.mimes' => 'File dokumentasi harus berupa foto (JPG, PNG, WEBP) atau video (MP4, MOV, WEBM, 3GP)',
        'photos.*.uploaded' => 'File gagal diunggah karena melebihi batas upload server. Kirim file yang lebih kecil, atau minta admin menaikkan upload_max_filesize di php.ini',
    ];

    /**
     * Upload limits in bytes, lowered to what PHP accepts: a file over upload_max_filesize or a
     * request over post_max_size never reaches validation, so the picker checks them up front.
     *
     * @return array{photo: int, video: int, total: int} total is 0 when PHP sets no limit
     */
    public static function limits(): array
    {
        $perFile = UploadedFile::getMaxFilesize();

        return [
            'photo' => (int) min(self::MAX_PHOTO_KB * 1024, $perFile),
            'video' => (int) min(self::MAX_VIDEO_KB * 1024, $perFile),
            'total' => self::iniBytes((string) ini_get('post_max_size')),
        ];
    }

    private static function iniBytes(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Validation rules for documentation photos and videos; each kind gets its own size limit.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,gif,bmp,webp,mp4,mov,webm,3gp', function (string $attribute, mixed $file, Closure $fail): void {
                if (! $file instanceof UploadedFile) {
                    return;
                }

                $isVideo = str_starts_with((string) $file->getMimeType(), 'video/');
                $maxKilobytes = $isVideo ? self::MAX_VIDEO_KB : self::MAX_PHOTO_KB;

                if ($file->getSize() > $maxKilobytes * 1024) {
                    $fail($isVideo ? 'Ukuran video maksimal 50 MB' : 'Ukuran foto maksimal 5 MB');
                }
            }],
        ];
    }

    /**
     * Save a documentation photo or video on the private disk and record it.
     *
     * @param  array{ticket_id?: int, ticket_history_id?: int, incident_id?: int, incident_update_id?: int}  $owner
     */
    public function store(UploadedFile $photo, User $actor, array $owner): TicketAttachment
    {
        $folder = isset($owner['incident_id']) ? "incidents/{$owner['incident_id']}" : "ticket-attachments/{$owner['ticket_id']}";

        return TicketAttachment::create($owner + [
            'user_id' => $actor->id,
            'path' => $photo->store($folder, TicketAttachment::DISK),
            'original_name' => $photo->getClientOriginalName(),
            'mime_type' => $photo->getMimeType(),
            'size' => $photo->getSize(),
        ]);
    }
}
