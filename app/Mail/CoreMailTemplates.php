<?php

namespace App\Mail;

use App\Modules\MailTemplates;

/**
 * Default text of the emails the core sends; management may edit each one
 * at /admin/mail-templates.
 */
class CoreMailTemplates
{
    public static function register(MailTemplates $templates): void
    {
        $templates->group('account', 'Hesap', 10);
        $templates->group('privacy', 'Kişisel veriler', 20);
        $templates->group('greetings', 'Kutlamalar', 25);

        $templates->register('contact.birthday', 'Doğum günü kutlaması', 'greetings',
            'Doğum gününüz kutlu olsun',
            '<p>Sayın {{name}},</p><p>{{organization}} olarak yeni yaşınızda ulaşmak istediğiniz tüm hedeflere başarıyla ulaşmanızı, mutluluğun ve sağlığın hayatınızdan eksik olmamasını dileriz. Doğum gününüz kutlu olsun.</p><p>{{organization}}</p>',
            ['name' => 'Ad soyad'],
            'Kurum ayarlarında seçilen sıfatları taşıyanlar, doğum gününde (izin verdiği kanallardan)', 10,
            sms: 'Sayın {{name}}, {{organization}} olarak yeni yaşınızda ulaşmak istediğiniz tüm hedeflere başarıyla ulaşmanızı, mutluluğun ve sağlığın hayatınızdan eksik olmamasını dileriz. Doğum gününüz kutlu olsun.');

        $templates->register('account.welcome', 'Kayıt: hoş geldiniz', 'account',
            '{{organization}} portalına hoş geldiniz',
            '<p>Sayın {{name}},</p><p>{{organization}} portalına hoş geldiniz. Yorum, eleştiri ve önerilerinizi {{contact_email}} adresine gönderebilirsiniz.</p><p>{{organization}}</p>',
            ['name' => 'Ad soyad', 'email' => 'E-posta adresi'], 'Kendi parolasıyla kayıt olan kişi', 10);

        $templates->register('account.created', 'Kayıt: parola belirleme', 'account',
            '{{organization}} portalına hoş geldiniz',
            '<p>Merhaba {{name}},</p><p>{{organization}} portalında hesabınız açıldı. Portala girebilmek için aşağıdaki bağlantıdan parolanızı belirleyin:</p><p><a href="{{link}}">{{link}}</a></p><p>Bağlantı {{minutes}} dakika geçerlidir. Süresi dolarsa <a href="{{reset_url}}">{{reset_url}}</a> adresinden e-postanızı yazarak yeni bir bağlantı isteyebilirsiniz.</p><p>{{organization}}</p>',
            ['name' => 'Ad soyad', 'link' => 'Parola belirleme bağlantısı', 'minutes' => 'Bağlantının geçerlilik süresi (dakika)', 'reset_url' => '"Şifremi unuttum" sayfasının adresi'],
            'Parolasız açılan hesabın sahibi (ör. web sitesindeki formdan)', 20);

        $templates->register('account.activation', 'Hesap etkinleştirme', 'account',
            '{{organization}} portalı hesabınızı etkinleştirin',
            '<p>Merhaba {{name}},</p><p>{{organization}} portalında kaydınız var; hesabınızı etkinleştirmek için aşağıdaki bağlantıdan parolanızı belirleyin:</p><p><a href="{{link}}">{{link}}</a></p><p>Bağlantı {{hours}} saat geçerlidir. Bu isteği siz yapmadıysanız bu e-postayı dikkate almayın.</p><p>{{organization}}</p>',
            ['name' => 'Ad soyad', 'link' => 'Etkinleştirme bağlantısı', 'hours' => 'Bağlantının geçerlilik süresi (saat)'],
            'Hesabı olmayan kayıtlı kişi', 30);

        $templates->register('account.password-reset', 'Parola sıfırlama', 'account',
            '{{organization}} portalı parola sıfırlama',
            '<p>Merhaba {{name}},</p><p>Hesabınız için parola sıfırlama isteği aldık. Yeni parolanızı belirlemek için aşağıdaki bağlantıya tıklayın:</p><p><a href="{{link}}">{{link}}</a></p><p>Bağlantı {{minutes}} dakika geçerlidir. Bu isteği siz yapmadıysanız bu e-postayı dikkate almayın; parolanız değişmez.</p><p>{{organization}}</p>',
            ['name' => 'Ad soyad', 'link' => 'Parola sıfırlama bağlantısı', 'minutes' => 'Bağlantının geçerlilik süresi (dakika)'],
            '"Şifremi unuttum" diyen kişi', 40);

        $templates->register('data-deletion.requested', 'Veri silme talebi (yönetime)', 'privacy',
            'Yeni veri silme talebi',
            '<p>Portalda yeni bir kişisel veri silme talebi var (#{{id}}).</p><p>Değerlendirmek için: <a href="{{admin_url}}">{{admin_url}}</a></p>',
            ['id' => 'Talep numarası', 'admin_url' => 'Yönetimdeki veri silme talepleri sayfası'],
            'Bildirim adresi (kurum ayarları)', 10);

        $templates->register('data-deletion.approved', 'Veri silme talebi onaylandı', 'privacy',
            'Kişisel veri silme talebiniz',
            '<p>{{organization}} portalındaki kişisel verilerinizin silinmesi talebiniz tamamlandı. Hesabınız kapatıldı ve kişisel verileriniz silindi.</p><p>{{organization}}</p>',
            [], 'Talep sahibi', 20);

        $templates->register('data-deletion.rejected', 'Veri silme talebi reddedildi', 'privacy',
            'Kişisel veri silme talebiniz',
            '<p>{{organization}} portalındaki kişisel verilerinizin silinmesi talebiniz reddedildi.</p><p>Gerekçe: {{reason}}</p><p>{{organization}}</p>',
            ['reason' => 'Ret gerekçesi'], 'Talep sahibi', 30);
    }
}
