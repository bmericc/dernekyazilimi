<?php

namespace Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class LetterAttachment extends Model
{
    use SoftDeletes;

    public const UPLOAD_MIMES = 'pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,jpg,jpeg,png,gif,tif,tiff,txt,csv,zip';

    protected $table = 'correspondence_attachments';

    protected $fillable = ['name', 'path', 'original_name', 'mime', 'size', 'sort'];

    protected static function booted(): void
    {
        // A removed attachment is kept (soft delete), so its file stays too.
        static::forceDeleted(function (self $attachment) {
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
