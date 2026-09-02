<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['ticket_reply_id', 'disk', 'path', 'filename', 'mime_type', 'size_bytes', 'is_inline', 'content_id'])]
class TicketAttachment extends Model
{
    protected function casts(): array
    {
        return [
            'is_inline' => 'boolean',
            'size_bytes' => 'integer',
        ];
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class, 'ticket_reply_id');
    }

    /**
     * Absolute filesystem path for building a Mailable/SendEmail attachment.
     */
    public function absolutePath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }
}
