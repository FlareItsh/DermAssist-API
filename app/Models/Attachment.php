<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['uuid', 'message_id', 'file_path', 'file_name', 'file_type', 'file_size'])]
class Attachment extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'message_attachments';

    protected $appends = ['url'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
