<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailTestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_test_email_now_or_through_the_queue(): void
    {
        Mail::fake();

        $this->artisan('email:test', ['email' => 'yanlis'])->assertFailed();
        Mail::assertNothingOutgoing();

        $this->artisan('email:test', ['email' => 'ada@example.org'])->expectsOutputToContain('Gönderildi → ada@example.org')->assertSuccessful();
        Mail::assertSent(Mailable::class, fn (Mailable $mail) => $mail->hasTo('ada@example.org') && str_starts_with($mail->subject, 'Test e-postası'));

        $this->artisan('email:test', ['email' => 'ada@example.org', '--queue' => true])->expectsOutputToContain('Kuyruğa atıldı')->assertSuccessful();
        Mail::assertQueued(Mailable::class);
    }
}
