<?php

namespace Modules\EmailChange;

use App\Modules\Menu;
use App\Modules\ModuleServiceProvider;
use App\Modules\Slots;
use Modules\EmailChange\Models\EmailChangeRequest;

/**
 * Account e-mail change requests, applied after management approval.
 * Other modules follow the change through the UserEmailChanging/UserEmailChanged events.
 */
class EmailChangeServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'email-change';
    }

    protected function bootModule(Menu $menu, Slots $slots): void
    {
        $this->mailTemplates()->group('email-change', 'E-posta değişikliği', 70);
        $this->mailTemplates()->register('email-change.submitted', 'Yeni talep (yönetime)', 'email-change',
            'E-posta değişikliği talebi',
            '<p>Yeni bir e-posta değişikliği talebi var.</p><ul><li>Kişi: {{name}}</li><li>Mevcut e-posta: {{current_email}}</li><li>İstenen e-posta: {{requested_email}}</li><li>Açıklama: {{reason}}</li></ul><p><a href="{{admin_url}}">{{admin_url}}</a></p>',
            ['name' => 'Talep sahibinin adı', 'current_email' => 'Mevcut e-posta', 'requested_email' => 'İstenen e-posta', 'reason' => 'Açıklama (boş olabilir)', 'admin_url' => 'Yönetimdeki talepler sayfası'],
            'Bildirim adresi (kurum ayarları)', 10);
        $this->mailTemplates()->register('email-change.approved', 'Talep onaylandı', 'email-change',
            'E-posta değişikliği talebiniz onaylandı',
            '<p>Merhaba {{name}},</p><p>E-posta değişikliği talebiniz onaylandı. E-posta adresiniz <strong>{{requested_email}}</strong> olarak güncellendi.</p><p>{{organization}}</p>',
            ['name' => 'Talep sahibinin adı', 'current_email' => 'Eski e-posta', 'requested_email' => 'Yeni e-posta'],
            'Talep sahibi (yeni adresine)', 20);
        $this->mailTemplates()->register('email-change.rejected', 'Talep reddedildi', 'email-change',
            'E-posta değişikliği talebiniz sonuçlandı',
            '<p>Merhaba {{name}},</p><p>E-posta değişikliği talebiniz yönetim tarafından onaylanmadı. E-posta adresiniz değiştirilmedi.</p><p>{{organization}}</p>',
            ['name' => 'Talep sahibinin adı', 'current_email' => 'Mevcut e-posta', 'requested_email' => 'İstenen e-posta'],
            'Talep sahibi (mevcut adresine)', 30);

        $menu->label('admin', 'email-change', null, 'mail-cog');
        $menu->add('admin', 'email-change', 'E-posta değişikliği talepleri', 'admin.email-change-requests', [1], 50);

        $this->dashboard()->stat('E-posta değişikliği talebi', 'mail-cog', fn () => EmailChangeRequest::where('status', 'pending')->count(), 'admin.email-change-requests', [1], 50, 'Onay bekleyen');

        // KVKK deletion: remove the personal data this module holds.
        \Illuminate\Support\Facades\Event::listen(\App\Events\ContactAnonymized::class, function (\App\Events\ContactAnonymized $event) {
            $event->userId && EmailChangeRequest::where('user_id', $event->userId)->delete();
        });

    }
}
