<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['category', 'title', 'slug', 'content', 'views', 'helpful', 'not_helpful', 'status'])]
class KnowledgeBase extends Model
{
    protected $table = 'knowledge_base';

    protected function casts(): array
    {
        return [
            'views' => 'integer',
            'helpful' => 'integer',
            'not_helpful' => 'integer',
        ];
    }
}
