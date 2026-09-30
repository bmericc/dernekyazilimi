<?php

namespace App\Console\Commands;

use App\Support\Organization;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class emailTest extends Command
{
    protected $signature = 'email:test {email : Alıcı e-posta adresi} {--queue : Kuyruğa at (kuyruk worker\'ının çalıştığını da sınar)}';

    protected $description = 'E-posta test gönder';

    public function handle(Organization $organization): int
    {
        $email = $this->argument('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Geçersiz e-posta adresi: {$email}");

            return self::FAILURE;
        }

        $mailer = config('mail.default');
        $this->line("Sürücü: {$mailer}".($mailer === 'smtp' ? ' ('.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').')' : '')
            .' · Gönderen: '.config('mail.from.address'));

        $sentAt = now()->format('d.m.Y H:i:s');
        $mail = (new Mailable())
            ->subject('Test e-postası · '.$organization->name())
            ->html("<p>Bu, {$organization->name()} portalından {$sentAt} tarihinde gönderilen bir test e-postasıdır.</p>");

        try {
            if ($this->option('queue')) {
                Mail::to($email)->queue($mail);
                $this->info("Kuyruğa atıldı → {$email} (".config('queue.default').'). Worker çalışıyorsa birazdan gelir.');
            } else {
                Mail::to($email)->send($mail);
                $this->info("Gönderildi → {$email}");
            }
        } catch (Throwable $e) {
            $this->error('Gönderilemedi: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
