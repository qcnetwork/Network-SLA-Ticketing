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
    ];

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
