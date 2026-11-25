<?php

namespace Modules\Admin\Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use App\Support\Anonymizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_removal_anonymizes_the_person_like_a_kvkk_deletion(): void
    {
        $owner = User::factory()->create(['role' => 1]);
        $user = User::factory()->create(['name' => 'Ada', 'surname' => 'Lovelace', 'email' => 'ada@example.org', 'national_id' => '10000000146']);
        $user->contact->affiliate('volunteer');
        $contactId = $user->contact_id;

        $this->actingAs($owner)->delete(route('admin.users.destroy', $user))->assertRedirect(route('admin.users'));

        $user->refresh();
        $this->assertSame(Anonymizer::NAME, $user->name);
        $this->assertNull($user->national_id);
        $this->assertSame(0, (int) $user->status);
        $contact = Contact::withTrashed()->find($contactId);
        $this->assertSame(Anonymizer::NAME, $contact->first_name);
        $this->assertNull($contact->email);
        $this->assertTrue($contact->trashed());
        $this->assertFalse($contact->hasAffiliation('volunteer'));
    }

    public function test_owner_cannot_remove_their_own_account(): void
    {
        $owner = User::factory()->create(['role' => 1, 'name' => 'Grace']);

        $this->actingAs($owner)->delete(route('admin.users.destroy', $owner))->assertSessionHas('danger-status');

        $this->assertSame('Grace', $owner->refresh()->name);
    }

    public function test_managers_cannot_remove_users(): void
    {
        $manager = User::factory()->create(['role' => 2]);
        $user = User::factory()->create(['name' => 'Ada']);

        $this->actingAs($manager)->delete(route('admin.users.destroy', $user));

        $this->assertSame('Ada', $user->refresh()->name);
    }
}
