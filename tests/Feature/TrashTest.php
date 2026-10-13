<?php

namespace Tests\Feature;

use App\Models\AffiliationType;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * No screen deletes a record for good: deleted records wait in "Silinenler".
 */
class TrashTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deleted_gateway_is_kept_and_can_be_restored(): void
    {
        $owner = User::factory()->create(['role' => 1]);
        $gateway = PaymentGateway::create(['driver' => 'iyzico', 'name' => 'Deneme kapısı', 'credentials' => ['api_key' => 'key-1', 'secret_key' => 'secret-1'], 'is_active' => true]);
        $payment = Payment::forceCreate(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'reference' => 'ABCD-2345', 'purpose' => 'other', 'method' => 'card', 'status' => 'pending', 'amount' => 10, 'currency' => 'TRY', 'payment_gateway_id' => $gateway->id]);

        $this->actingAs($owner)->delete("/admin/payment-gateways/{$gateway->id}")->assertSessionHas('success-status');

        $this->assertSame(0, PaymentGateway::count());
        $this->assertSame(1, DB::table('payment_gateways')->count());
        $this->assertSame('Deneme kapısı', $payment->fresh()->gateway->name);
        $this->actingAs($owner)->get('/admin/payment-gateways')->assertOk()->assertDontSee(route('payments.callback', $gateway));
        $this->actingAs($owner)->get('/admin/trash')->assertOk()->assertSee('Deneme kapısı')->assertDontSee('secret-1');

        $this->actingAs($owner)->patch("/admin/trash/payment-gateway/{$gateway->id}")->assertSessionHas('success-status');

        $this->assertSame('secret-1', PaymentGateway::sole()->credential('secret_key'));
        $this->actingAs($owner)->patch("/admin/trash/payment-gateway/{$gateway->id}")->assertNotFound();
    }

    public function test_a_deleted_tag_keeps_its_contacts_and_its_name(): void
    {
        $owner = User::factory()->create(['role' => 1]);
        $tag = Tag::create(['name' => 'Basın', 'color' => 'blue']);
        $owner->contact->tags()->attach($tag);

        $this->actingAs($owner)->delete("/admin/tags/{$tag->id}");

        $this->assertSame(0, $owner->contact->tags()->count());
        $this->actingAs($owner)->post('/admin/tags', ['name' => 'Basın', 'color' => 'blue'])->assertSessionHasErrors('name');

        $this->actingAs($owner)->patch("/admin/trash/tag/{$tag->id}");
        $this->assertSame(['Basın'], $owner->contact->tags()->pluck('name')->all());
    }

    public function test_a_deleted_affiliation_record_gives_no_roles(): void
    {
        $owner = User::factory()->create(['role' => 1]);
        $role = Role::create(['key' => 'editor', 'name' => 'Editör']);
        $role->syncPermissions(['admin.access']);
        $type = AffiliationType::create(['key' => 'trash-test', 'name' => 'Deneme sıfatı']);
        $type->roles()->sync([$role->id]);
        $user = User::factory()->create();
        $affiliation = $user->contact->affiliate('trash-test');
        $this->assertTrue($user->fresh()->hasRole('editor'));

        $this->actingAs($owner)->delete("/admin/contacts/{$user->contact->id}/affiliations/{$affiliation->id}");
        $this->assertFalse($user->fresh()->hasRole('editor'));

        $this->actingAs($owner)->patch("/admin/trash/affiliation/{$affiliation->id}");
        $this->assertTrue($user->fresh()->hasRole('editor'));
    }

    public function test_the_trash_needs_its_permission(): void
    {
        $manager = User::factory()->create(['role' => 2]);

        $this->actingAs($manager)->get('/admin/trash')->assertForbidden();
    }
}
