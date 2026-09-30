<?php

namespace App\Support\Messaging;

use App\Contracts\Messaging\SmsSender;
use BahriCanli\Netgsm\ShortMessage;
use NotificationChannels\Netgsm\Netgsm;
use RuntimeException;

class NetgsmSmsSender implements SmsSender
{
    // NetGSM XML API result codes; 00, 01 and 02 mean the message was accepted.
    private const ERRORS = [
        '20' => 'mesaj metni hatalı ya da çok uzun',
        '30' => 'geçersiz kullanıcı adı/parola, API erişim izni yok ya da sunucu IP adresi izinli değil',
        '40' => 'gönderici adı (originator) sistemde tanımlı değil',
        '50' => 'İYS kontrollü gönderim bu hesapta yapılamıyor',
        '51' => 'İYS marka bilgisi bulunamadı',
        '70' => 'hatalı sorgu, parametreler eksik ya da hatalı',
        '80' => 'gönderim sınırı aşıldı',
        '85' => 'aynı numaraya mükerrer gönderim sınırı aşıldı',
    ];

    public function send(string $phone, string $text): void
    {
        // NetGSM's default encoding has no Turkish letters.
        $text = str_replace(['ı', 'ü', 'ö', 'ç', 'ş', 'ğ', 'İ', 'Ü', 'Ö', 'Ç', 'Ş', 'Ğ'], ['i', 'u', 'o', 'c', 's', 'g', 'I', 'U', 'O', 'C', 'S', 'G'], $text);

        $response = Netgsm::sendShortMessage(new ShortMessage($phone, $text));
        $code = trim((string) $response->statusCode());

        if (! in_array($code, ['00', '01', '02'], true)) {
            throw new RuntimeException('NetGSM SMS gönderilemedi ('.($code ?: 'boş yanıt').'): '.(self::ERRORS[$code] ?? 'bilinmeyen hata'));
        }
    }
}
