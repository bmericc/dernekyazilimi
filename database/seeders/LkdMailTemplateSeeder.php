<?php

namespace Database\Seeders;

use App\Models\MailTemplate;
use Illuminate\Database\Seeder;

/**
 * LKD sends an SMS along with these emails, as it did on Fonzip. Turns the
 * SMS on only where management has not decided yet, so it can run again.
 */
class LkdMailTemplateSeeder extends Seeder
{
    public const SMS = [
        'contact.birthday',
        'membership.approved',
        'membership.identity-missing',
        'membership.photo-missing',
        'dues.entry-fee',
        'dues.entry-fee-reminder',
        'dues.reminder',
        'donation.thanks',
    ];

    public function run(): void
    {
        foreach (self::SMS as $key) {
            $template = MailTemplate::firstOrNew(['key' => $key]);
            if ($template->sms_enabled === null) {
                $template->sms_enabled = true;
                $template->save();
            }
        }
    }
}
