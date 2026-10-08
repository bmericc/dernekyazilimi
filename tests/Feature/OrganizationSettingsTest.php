<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\Role;
use App\Models\User;
use App\Support\Organization;
use Database\Seeders\LkdOrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesImages;
use Tests\TestCase;

class OrganizationSettingsTest extends TestCase
{
    use MakesImages, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function settingsManager(): User
    {
        $role = Role::create(['key' => 'settings-manager', 'name' => 'Ayar sorumlusu']);
        $role->syncPermissions(['admin.access', 'settings.manage']);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    private function valid(array $overrides = []): array
    {
        return $overrides + ['name' => 'Örnek Derneği'];
    }

    public function test_a_fresh_installation_shows_no_association_details(): void
    {
        config(['organization.name' => 'Örnek Derneği']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Örnek Derneği', $html);
        // LKD-only modules (e.g. representation) may still link to LKD; the core does not.
        $this->assertStringNotContainsString('gonullu@lkd.org.tr', $html);
        $this->assertStringNotContainsString('lkd-gonullusu', $html);
        $this->assertStringNotContainsString('googletagmanager', $html);
        $this->assertStringNotContainsString('Soru, şikâyet', $html);
    }

    public function test_the_settings_need_the_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => 2]))->get('/admin/settings/organization')->assertForbidden();
        $this->actingAs($this->settingsManager())->get('/admin/settings/organization')->assertOk()->assertSee('Kurum ayarları');
    }

    public function test_official_details_of_the_association_are_kept(): void
    {
        $manager = $this->settingsManager();

        $this->actingAs($manager)->put('/admin/settings/organization', $this->valid(['tax_number' => '12345678901', 'mersis_no' => '123']))
            ->assertSessionHasErrors(['tax_number', 'mersis_no']);

        $this->actingAs($manager)->put('/admin/settings/organization', $this->valid([
            'address' => 'PK 50, 06430 Yenişehir / Ankara',
            'registry_no' => '06-061-115',
            'tax_office' => 'Çankaya',
            'tax_number' => '1234567890',
            'mersis_no' => '0123456789012345',
        ]))->assertSessionHasNoErrors();

        $organization = app(Organization::class);
        $this->assertSame('06-061-115', $organization->get('registry_no'));
        $this->assertSame('Çankaya', $organization->get('tax_office'));
        $this->assertSame('1234567890', $organization->get('tax_number'));
        $this->assertSame('0123456789012345', $organization->get('mersis_no'));

        $this->actingAs($manager)->get('/admin/settings/organization')->assertOk()
            ->assertSee('Dernek kütük numarası')->assertSee('06-061-115')->assertSee('Vergi dairesi')->assertSee('0123456789012345');
    }

    public function test_saved_settings_appear_on_the_site(): void
    {
        $this->actingAs($this->settingsManager())->put('/admin/settings/organization', $this->valid([
            'short_name' => 'ÖD',
            'contact_email' => 'bilgi@ornek.org.tr',
            'primary_color' => '#1E88E5',
            'social_mastodon' => 'https://mastodon.example/@ornek',
            'ga_measurement_id' => 'G-ABC123XYZ',
            'home_title' => 'Hoş geldiniz',
            'home_content' => '<h2 style="text-align: center">Biz kimiz?</h2><p>Metin</p><img src="https://ornek.org.tr/a.png" alt="a"><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
            'logo' => $this->fakePng('logo.png', 300, 100),
        ]))->assertRedirect('/admin/settings/organization');

        $organization = app(Organization::class);
        $this->assertSame('#1e88e5', $organization->get('primary_color'));
        Storage::disk('local')->assertExists($organization->get('logo_path'));

        auth()->logout();
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Hoş geldiniz', $html);
        $this->assertStringContainsString('<h2 style="text-align:center;">Biz kimiz?</h2>', $html);
        $this->assertStringContainsString('src="https://ornek.org.tr/a.png"', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringContainsString('mailto:bilgi@ornek.org.tr', $html);
        $this->assertStringContainsString('https://mastodon.example/@ornek', $html);
        $this->assertStringContainsString('--tblr-primary:#1e88e5', $html);
        $this->assertStringContainsString('gtag/js?id=G-ABC123XYZ', $html);
        $this->assertStringContainsString($organization->logoUrl(), $html);
        $this->get($organization->logoUrl())->assertOk();
    }

    public function test_google_tags_are_added_to_iframe_pages(): void
    {
        app(Organization::class)->save(['ga_measurement_id' => 'G-ABC123XYZ', 'gtm_container_id' => 'GTM-ABC123']);

        $html = $this->get('/login?in-iframe=1')->assertOk()->getContent();

        $this->assertStringContainsString('gtag/js?id=G-ABC123XYZ', $html);
        $this->assertStringContainsString('"GTM-ABC123"', $html);
        $this->assertStringContainsString('ns.html?id=GTM-ABC123', $html);
    }

    public function test_google_tags_are_added_to_the_agreement_iframe_and_the_admin_panel(): void
    {
        app(Organization::class)->save(['ga_measurement_id' => 'G-ABC123XYZ', 'gtm_container_id' => 'GTM-ABC123']);
        $agreement = Agreement::create(['key' => 'kvkk', 'title' => 'Gizlilik Politikası']);
        $agreement->versions()->create(['version' => 1, 'content' => '<p>Metin</p>'])->forceFill(['published_at' => now()])->save();

        $html = $this->get('/agreements/kvkk?iframe=1')->assertOk()->getContent();

        $this->assertStringContainsString('gtag/js?id=G-ABC123XYZ', $html);
        $this->assertStringContainsString('"GTM-ABC123"', $html);
        $this->assertStringContainsString('ns.html?id=GTM-ABC123', $html);

        $this->actingAs($this->settingsManager())->get('/admin/settings/organization')
            ->assertOk()->assertSee('ns.html?id=GTM-ABC123', false);
    }

    public function test_the_home_editor_starts_from_the_default_home_page(): void
    {
        app(Organization::class)->save(['name' => 'Örnek Derneği']);
        $manager = $this->settingsManager();

        $this->actingAs($manager)->get('/admin/settings/organization')
            ->assertOk()
            ->assertSee(' data-home-default ', false)
            ->assertSee('Örnek Derneği gönüllüleri', false)
            ->assertSee('placeholder="Örnek Derneği Gönüllüsü Nedir?"', false);

        $this->post('/logout');
        $this->get('/')->assertSee('Örnek Derneği Gönüllüsü Nedir?')->assertDontSee('Linux Kullanıcıları Derneği');

        app(Organization::class)->save(['home_content' => '<p>Yazılmış metin</p>']);
        $this->actingAs($manager)->get('/admin/settings/organization')
            ->assertDontSee(' data-home-default ', false)
            ->assertSee('Yazılmış metin', false);
    }

    public function test_a_failed_save_says_so_at_the_top(): void
    {
        $this->actingAs($this->settingsManager())->from('/admin/settings/organization')
            ->put('/admin/settings/organization', $this->valid(['home_content' => '<p>Metin</p>', 'logo' => $this->fakePng('logo.png', 300, 100)->size(3000)]))
            ->assertSessionHasErrors('logo');

        $this->get('/admin/settings/organization')->assertSee('Ayarlar kaydedilmedi');
        $this->assertNull(app(Organization::class)->get('home_content'));
    }

    public function test_the_iframe_policy_follows_the_setting(): void
    {
        $this->get('/login?in-iframe=1')->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");

        app(Organization::class)->save(['frame_ancestors' => "https://ornek.org.tr\nhttps://www.ornek.org.tr"]);

        $this->get('/login?in-iframe=1')->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://ornek.org.tr https://www.ornek.org.tr");
    }

    public function test_invalid_values_are_refused(): void
    {
        $this->actingAs($this->settingsManager())->put('/admin/settings/organization', $this->valid([
            'frame_ancestors' => "https://ok.org.tr\nhttp://plain.org.tr",
            'primary_color' => 'red',
            'social_x' => 'javascript:alert(1)',
            'gtm_container_id' => 'not-an-id',
        ]))->assertSessionHasErrors(['frame_ancestors', 'primary_color', 'social_x', 'gtm_container_id']);
    }

    public function test_emptied_fields_fall_back_to_defaults_and_the_logo_can_be_removed(): void
    {
        $manager = $this->settingsManager();
        $this->actingAs($manager)->put('/admin/settings/organization', $this->valid(['short_name' => 'ÖD', 'logo' => $this->fakePng('logo.png', 300, 100)]));
        $logo = app(Organization::class)->get('logo_path');

        $this->actingAs($manager)->put('/admin/settings/organization', $this->valid(['short_name' => '', 'remove_logo' => '1']));

        $organization = app(Organization::class);
        $this->assertNull($organization->get('short_name'));
        $this->assertSame('Örnek Derneği', $organization->shortName());
        $this->assertNull($organization->logoUrl());
        Storage::disk('local')->assertMissing($logo);
    }

    public function test_editor_images_are_uploaded_and_served(): void
    {
        $location = $this->actingAs($this->settingsManager())
            ->postJson('/admin/settings/organization/images', ['file' => $this->fakePng('photo.png', 400, 300)])
            ->assertOk()->json('location');

        auth()->logout();
        $this->get($location)->assertOk();

        $this->actingAs(User::factory()->create(['role' => 2]))
            ->postJson('/admin/settings/organization/images', ['file' => $this->fakePng('photo.png', 400, 300)])
            ->assertForbidden();
    }

    public function test_the_lkd_seeder_fills_only_empty_settings(): void
    {
        app(Organization::class)->save(['contact_email' => 'kept@ornek.org.tr']);

        $this->seed(LkdOrganizationSeeder::class);

        $organization = app(Organization::class);
        $this->assertSame('kept@ornek.org.tr', $organization->get('contact_email'));
        $this->assertSame('yk@lkd.org.tr', $organization->notificationEmail());
        $this->assertSame('LKD', $organization->shortName());
        $this->assertSame(['volunteer' => 'penguen.org.tr', 'member' => 'penguen.org.tr'], json_decode($organization->get('mail_forwarding_domains'), true));
        Storage::disk('local')->assertExists($organization->get('logo_path'));
    }
}
