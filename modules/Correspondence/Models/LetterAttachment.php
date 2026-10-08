<?php

namespace Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class LetterAttachment extends Model
{
    protected $table = 'correspondence_attachments';

    protected $fillable = ['name', 'path', 'original_name', 'mime', 'size', 'sort'];

    protected static function booted(): void
    {
        static::deleted(function (self $attachment) {
            if ($attachment->path) {
                Storage::disk('local')->delete($attachment->path);
            }
        });
    }

    /** A physical attachment has no file. */
    public function hasFile(): bool
    {
        return $this->path !== null;
    }
}
