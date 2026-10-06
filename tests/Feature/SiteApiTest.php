<?php

namespace Tests\Feature;

use App\Mail\AccountCreated;
use App\Models\Agreement;
use App\Models\AgreementAcceptance;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Support\Consents;
use App\Support\Embed;
use App\Support\Organization;
use App\Support\SiteApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SiteApiTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        app(Organization::class)->save(['name' => 'Örnek Derneği', 'frame_ancestors' => "https://www.ornek.org.tr\nhttps://ornek.org.tr"]);
        $this->key = app(SiteApi::class)->generateKey();
    }

    private function site(array $headers = []): static
    {
        return $this->withHeaders($headers + ['X-Api-Key' => $this->key, 'X-Site-Url' => 'https://www.ornek.org.tr/', 'X-Client-Ip' => '203.0.113.7']);
    }

    private function verifiedPhone(string $phone = '905551112233'): void
    {
        PhoneVerification::create(['value_type' => 'phone_number', 'value' => $phone, 'verified' => true, 'verified_at' => now(), 'status' => 1]);
    }

    private function person(array $overrides = []): array
    {
        return array_replace(['name' => 'ada', 'surname' => 'lovelace', 'email' => 'Ada@Example.org', 'phone_number' => '905551112233', 'consents' => ['email' => true]], $overrides);
    }

    public function test_the_api_needs_the_key_and_an_allowed_site(): void
    {
        $this->getJson('/api/site/config')->assertUnauthorized();
        $this->site(['X-Api-Key' => 'dy_wrong'])->getJson('/api/site/config')->assertUnauthorized();
        $this->site(['X-Site-Url' => 'https://www.baska.org.tr'])->getJson('/api/site/config')->assertForbidden();
        $this->site(['X-Site-Url' => 'http://www.ornek.org.tr'])->getJson('/api/site/config')->assertForbidden();

        $this->site()->getJson('/api/site/config')->assertOk()
            ->assertJsonPath('organization.name', 'Örnek Derneği')
            ->assertJsonPath('privacy', null)
            ->assertJsonPath('registration.consents.sms', Consents::CHANNELS['sms'])
            ->assertJsonPath('membership.open', false)
            ->assertJsonPath('donation.open', false)
            ->assertJsonPath('volunteer.open', true);

        // A renewed key replaces the old one; a revoked one closes the API.
        $old = $this->key;
        $this->key = app(SiteApi::class)->generateKey();
        $this->site(['X-Api-Key' => $old])->getJson('/api/site/config')->assertUnauthorized();
        $this->site()->getJson('/api/site/config')->assertOk();
        app(SiteApi::class)->revokeKey();
        $this->site()->getJson('/api/site/config')->assertUnauthorized();
    }

    public function test_the_key_is_made_in_the_organization_settings_and_shown_once(): void
    {
        $owner = User::factory()->create(['role' => 1]);

        $this->actingAs($owner)->post('/admin/settings/organization/site-api-key')->assertRedirect()->assertSessionHas('site-api-key');
        $key = session('site-api-key');
        $this->assertTrue(app(SiteApi::class)->validKey($key));
        $this->assertFalse(app(SiteApi::class)->validKey($this->key));
        $this->assertDatabaseMissing('settings', ['value' => $key]);

        $this->actingAs($owner)->get('/admin/settings/organization')->assertOk()->assertSee('Anahtarı yenile');
        $this->actingAs($owner)->delete('/admin/settings/organization/site-api-key')->assertRedirect();
        $this->assertFalse(app(SiteApi::class)->hasKey());
    }

    public function test_the_site_verifies_a_phone_number(): void
    {
        Notification::fake();

        $this->site()->postJson('/api/site/phone-verifications', ['phone_number' => 'abc'])->assertUnprocessable();
        $this->site()->postJson('/api/site/phone-verifications', ['phone_number' => '905551112233'])->assertOk()->assertJson(['status' => true]);

        $verification = PhoneVerification::where('value', '905551112233')->firstOrFail();
        $verification->forceFill(['verification_code' => Hash::make('123456')])->save();

        $this->site()->postJson('/api/site/phone-verifications/verify', ['phone_number' => '905551112233', 'code' => '000000'])->assertUnprocessable()->assertJsonPath('message', 'Doğrulama kodu hatalı.');
        $this->site()->postJson('/api/site/phone-verifications/verify', ['phone_number' => '905551112233', 'code' => '123456'])->assertOk();
        $this->assertTrue($verification->fresh()->verified);
    }

    public function test_the_site_opens_an_account_without_a_password(): void
    {
        $agreement = Agreement::create(['key' => Agreement::PRIVACY, 'title' => 'Gizlilik Politikası']);
        $agreement->versions()->create(['version' => 1, 'content' => '<p>Metin</p>'])->forceFill(['published_at' => now()])->save();

        $this->site()->getJson('/api/site/config')->assertJsonPath('privacy.title', 'Gizlilik Politikası');

        // Not without the verified phone and the accepted privacy policy.
        $this->site()->postJson('/api/site/accounts', $this->person(['agreement' => true]))->assertUnprocessable()->assertJsonValidationErrors('phone_number');
        $this->verifiedPhone();
        $this->site()->postJson('/api/site/accounts', $this->person())->assertUnprocessable()->assertJsonValidationErrors('agreement');

        $this->site()->postJson('/api/site/accounts', $this->person(['agreement' => true]))->assertCreated()->assertJson(['created' => true, 'continue_url' => null]);

        $user = User::where('email', 'ada@example.org')->firstOrFail();
        $this->assertSame('Ada', $user->name);
        $this->assertSame('Lovelace', $user->surname);
        $this->assertNotNull($user->phone_number_verified_at);
        $this->assertTrue($user->contact->hasAffiliation('volunteer'));
        $this->assertSame(['email' => true, 'sms' => false, 'whatsapp' => false], array_intersect_key(app(Consents::class)->current($user->contact), ['email' => 1, 'sms' => 1, 'whatsapp' => 1]));
        $this->assertSame('203.0.113.7', AgreementAcceptance::where('user_id', $user->id)->sole()->ip);

        // The person sets the password from the link in the email.
        Mail::assertQueued(AccountCreated::class, function (AccountCreated $mail) use ($user) {
            $this->get($mail->link)->assertOk();

            return $mail->hasTo($user->email) && str_contains($mail->link, '/password/reset/');
        });

        $this->site()->postJson('/api/site/accounts', $this->person(['agreement' => true]))->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_a_new_account_continues_on_a_framed_page_with_a_one_time_link(): void
    {
        $this->verifiedPhone();

        $this->site()->postJson('/api/site/accounts', $this->person(['continue' => 'nowhere']))->assertUnprocessable();
        $link = $this->site()->postJson('/api/site/accounts', $this->person(['continue' => 'membership']))->assertCreated()->json('continue_url');
        $user = User::where('email', 'ada@example.org')->firstOrFail();

        $this->flushHeaders();
        $this->get($link.'x')->assertForbidden();
        $this->get($link)->assertRedirect(route('membership.apply', ['in-iframe' => 1]));
        $this->assertAuthenticatedAs($user);

        // The link works once.
        auth()->logout();
        $this->get($link)->assertRedirect(route('membership.apply', ['in-iframe' => 1]));
        $this->assertGuest();

        // An email that already has an account is sent to the page, which asks to sign in.
        $this->site()->postJson('/api/site/accounts', $this->person(['continue' => 'membership']))->assertOk()
            ->assertExactJson(['created' => false, 'continue_url' => route('membership.apply', ['in-iframe' => 1])]);
        $this->assertSame(1, User::count());
    }

    public function test_framed_pages_use_their_own_session_and_the_bare_layout(): void
    {
        $user = User::factory()->create();

        $framed = $this->actingAs($user)->get('/my-infos', ['Sec-Fetch-Dest' => 'iframe'])->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://www.ornek.org.tr https://ornek.org.tr")
            ->assertSee('dernekyazilimi:height')->assertDontSee('navbar-brand');
        // The frame's session lives in its own partitioned cookie.
        $this->assertStringEndsWith('_embed', config('session.cookie'));
        $this->assertSame('none', config('session.same_site'));
        $this->assertTrue(config('session.secure'));
        $this->assertTrue(config('session.partitioned'));
        $this->assertNull(collect($framed->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN'));
        $this->assertTrue(app(Embed::class)->active());
    }

    public function test_pages_outside_a_frame_are_unchanged_and_the_admin_panel_is_never_framed(): void
    {
        $owner = User::factory()->create(['role' => 1]);

        $plain = $this->actingAs($owner)->get('/my-infos')->assertOk()->assertSee('navbar-brand')->assertDontSee('dernekyazilimi:height');
        $this->assertFalse($plain->headers->has('Content-Security-Policy'));
        $this->assertStringEndsNotWith('_embed', config('session.cookie'));

        $this->actingAs($owner)->get('/admin?in-iframe=1')->assertForbidden();
        $this->actingAs($owner)->get('/admin', ['Sec-Fetch-Dest' => 'iframe'])->assertForbidden();
    }
}
