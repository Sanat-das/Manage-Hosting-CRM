<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['session_id', 'user_id', 'sender_type', 'message'])]
class ChatMessage extends Model
{
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }
}
