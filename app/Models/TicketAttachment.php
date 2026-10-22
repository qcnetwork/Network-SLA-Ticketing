<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TicketAttachment extends Model
{
    /**
     * Private disk: photos and videos are served through an authorized route, never publicly.
     */
    public const DISK = 'local';

    protected $guarded = ['id'];

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    /**
     * Serve the file inline with byte-range support, which browsers need to play and seek videos.
     * The sandbox policy keeps an uploaded file from ever running script on the app's origin,
     * even if a browser were to sniff it as HTML.
     */
    public function inlineResponse(): BinaryFileResponse
    {
        // Symfony rejects a fallback name with anything outside printable ASCII, which would make the file unviewable.
        $fallback = preg_replace('/[^\x20-\x7e]|[%\/\\\\"]/', '_', Str::ascii($this->original_name)) ?: 'lampiran';

        return response()->file(Storage::disk(self::DISK)->path($this->path), [
            'Content-Type' => $this->mime_type,
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self'; media-src 'self'",
        ])->setContentDisposition('inline', $this->original_name, $fallback);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function history(): BelongsTo
    {
        return $this->belongsTo(TicketHistory::class, 'ticket_history_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
