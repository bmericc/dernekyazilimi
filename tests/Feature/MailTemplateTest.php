<?php

namespace Tests\Feature;

use App\Mail\AccountActivation;
use App\Mail\TemplateMail;
use App\Models\MailTemplate;
use App\Models\User;
use App\Modules\MailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

class MailTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_placeholders_are_filled_and_escaped(): void
    {
        $templates = app(MailTemplates::class);

        $filled = $templates->fill(
            'Merhaba {{ name }}',
            '<p>{{name}} — {{list}} — <a href="%7B%7Blink%7D%7D">aç</a> — {{unknown}} — {{organization}}</p>',
            ['name' => '<b>Ada</b>', 'list' => new HtmlString('<ul><li>bir</li></ul>'), 'link' => 'https://example.org/x?a=1&b=2'],
        );

        $this->assertSame('Merhaba Ada', $filled['subject']);
        $body = $filled['body']->toHtml();
        $this->assertStringContainsString('&lt;b&gt;Ada&lt;/b&gt;', $body);
        $this->assertStringContainsString('<ul><li>bir</li></ul>', $body);
        $this->assertStringContainsString('href="https://example.org/x?a=1&amp;b=2"', $body);
        $this->assertStringContainsString('{{unknown}}', $body);
        $this->assertStringNotContainsString('{{organization}}', $body);
    }

    public function test_every_default_template_uses_only_its_own_placeholders(): void
    {
        $templates = app(MailTemplates::class);

        foreach ($templates->grouped() as $group) {
            foreach ($group['templates'] as $template) {
                $filled = $templates->render($template['key'], $templates->samples($template['key']));
                $this->assertStringNotContainsString('{{', $filled['subject'].$filled['body']->toHtml(), $template['key']);
            }
        }
    }

    public function test_management_edits_a_template_and_the_mail_uses_it(): void
    {
        $owner = User::factory()->create(['role' => 1]);

        $this->actingAs($owner)->get('/admin/mail-templates')->assertOk()->assertSee('account.activation')->assertSee('Hesap etkinleştirme');
        $this->actingAs($owner)->get('/admin/mail-templates/account.activation')->assertOk()->assertSee('{{link}}', false);

        $this->actingAs($owner)->put('/admin/mail-templates/account.activation', [
            'subject' => 'Hoşgeldiniz {{name}}',
            'body' => '<p>Selam {{name}}, <a href="{{link}}">buradan</a> başlayın.</p><script>alert(1)</script>',
        ])->assertRedirect('/admin/mail-templates/account.activation');

        $mail = (new AccountActivation('Ada Lovelace', 'https://portal.test/aktif?x=1'))->build();
        $this->assertSame('Hoşgeldiniz Ada Lovelace', $mail->subject);
        $html = $mail->render();
        $this->assertStringContainsString('Selam Ada Lovelace', $html);
        $this->assertStringContainsString('href="https://portal.test/aktif?x=1"', $html);
        $this->assertStringNotContainsString('<script>', $html);

        $this->actingAs($owner)->delete('/admin/mail-templates/account.activation')->assertRedirect();
        $this->assertNull(MailTemplate::where('key', 'account.activation')->value('body'));
        $this->assertStringContainsString('hesabınızı etkinleştirmek için', (new AccountActivation('Ada', 'https://portal.test'))->render());

        $this->actingAs(User::factory()->create(['role' => 2]))->get('/admin/mail-templates')->assertForbidden();
    }

    public function test_a_test_mail_goes_to_the_signed_in_user(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['role' => 1, 'email' => 'owner@example.org']);

        $this->actingAs($owner)->post('/admin/mail-templates/account.activation/test')->assertSessionHas('success-status');

        Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail) => $mail->hasTo('owner@example.org') && $mail->key === 'account.activation');
    }

    public function test_the_password_reset_link_goes_out_in_the_template(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'ada@example.org']);

        Password::sendResetLink(['email' => 'ada@example.org']);

        Mail::assertQueued(TemplateMail::class, fn (TemplateMail $mail) => $mail->hasTo('ada@example.org')
            && $mail->key === 'account.password-reset' && str_contains($mail->values['link'], '/password/reset/'));
    }
}
