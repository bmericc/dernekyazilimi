<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * The edited subject and body of an email template; empty fields fall back
 * to the default text registered in code (App\Modules\MailTemplates).
 */
class MailTemplate extends Model
{
    use Auditable;

    protected $fillable = ['key', 'subject', 'body', 'updated_by'];

    public function auditLabel(): string
    {
        return app(\App\Modules\MailTemplates::class)->label($this->key) ?? $this->key;
    }
}
