<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file attached to a chat message. Same shape as TicketAttachment: the disk
 * and the disk-relative path are stored separately and the absolute path is
 * only ever derived, never persisted.
 */
#[Fillable(['message_id', 'disk', 'path', 'filename', 'mime_type', 'size_bytes', 'is_inline', 'content_id'])]
class ChatMessageAttachment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_inline' => 'boolean',
            'size_bytes' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatConversationMessage::class, 'message_id');
    }

    /**
     * Absolute filesystem path — for streaming a download, never for storage.
     */
    public function absolutePath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return $unit === 'B'
                    ? $bytes.' B'
                    : number_format($bytes, 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return $bytes.' B';
    }
}
