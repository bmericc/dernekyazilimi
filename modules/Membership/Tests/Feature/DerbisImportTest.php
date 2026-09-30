<?php

namespace Modules\Membership\Tests\Feature;

use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use App\Support\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Membership\Models\Membership;
use Modules\Membership\Support\DerbisImport;
use Tests\TestCase;

class DerbisImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Üye Niteliği', 'Ad Soyad / Temsilci Bilgileri', 'T.C. Kimlik No', 'Cinsiyet', 'Tüzel Adı', 'Tüzel Numarası', 'Telefon No', 'Meslek', 'Öğrenim Durumu', 'E-Posta', 'İnternet Sitesi', 'Üye Tür', 'Onursal Üye', 'Durum', 'Yönetim Kurulu Karar Tarihi', 'Doğum Tarihi', 'Kayıt Tarihi', 'Pasif Olma Tarihi', 'Pasif Olma Nedeni', 'Pasif Olma Bildirim Tarihi'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** A valid T.C. kimlik no from nine digits. */
    private function identity(string $nine): string
    {
        $d = array_map('intval', str_split($nine));
        $tenth = ((($d[0] + $d[2] + $d[4] + $d[6] + $d[8]) * 7 - ($d[1] + $d[3] + $d[5] + $d[7])) % 10 + 10) % 10;
        $eleventh = (array_sum($d) + $tenth) % 10;

        return $nine.$tenth.$eleventh;
    }

    private function row(array $values): array
    {
        $row = array_fill_keys(self::HEADER, null);
        $row['Üye Niteliği'] = 'Gerçek';
        $row['Durum'] = 'Aktif';

        return array_values(array_merge($row, $values));
    }

    private function csv(array $rows): UploadedFile
    {
        $handle = fopen('php://memory', 'r+');
        fputcsv($handle, self::HEADER, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '');
        }
        rewind($handle);

        return UploadedFile::fake()->createWithContent('liste.csv', stream_get_contents($handle));
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => 1]);
    }

    public function test_the_page_needs_the_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/memberships/import')->assertForbidden();
        $this->actingAs($this->manager())->get('/admin/memberships/import')->assertOk()->assertSee('Kurum Üyelik Listesi');
    }

    public function test_rows_are_previewed_then_imported(): void
    {
        $manager = $this->manager();
        $existing = Contact::create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'identity_number' => $this->identity('123456789')]);
        $byEmail = Contact::create(['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@ornek.test']);
        $leaving = Contact::create(['first_name' => 'Alan', 'last_name' => 'Turing', 'identity_number' => $this->identity('223456789')]);
        Membership::create(['contact_id' => $leaving->id, 'number' => '7', 'joined_at' => '2015-01-01']);
        $leaving->affiliate('member', ['started_at' => '2015-01-01']);
        $notListed = Contact::create(['first_name' => 'Liste', 'last_name' => 'Dışı']);
        Membership::create(['contact_id' => $notListed->id, 'number' => '9']);
        $profession = CustomField::create(['key' => 'meslek', 'label' => 'Meslek', 'type' => 'text', 'group' => 'personal']);

        $file = $this->csv([
            $this->row(['Ad Soyad / Temsilci Bilgileri' => 'ADA LOVELACE', 'T.C. Kimlik No' => $this->identity('123456789'), 'Telefon No' => '05321234567', 'Kayıt Tarihi' => '02.07.2018', 'Doğum Tarihi' => '10.12.1985', 'Cinsiyet' => 'KADIN', 'Meslek' => 'BİLGİSAYAR MÜHENDİSİ']),
            $this->row(['Ad Soyad / Temsilci Bilgileri' => 'GRACE BREWSTER HOPPER', 'T.C. Kimlik No' => $this->identity('323456789'), 'E-Posta' => 'Grace@Ornek.test']),
            $this->row(['Ad Soyad / Temsilci Bilgileri' => 'İSMAİL IŞIK', 'T.C. Kimlik No' => $this->identity('423456789'), 'E-Posta' => 'ismail@ornek.test', 'Kayıt Tarihi' => '2020-05-04', 'Cinsiyet' => 'ERKEK']),
            $this->row(['Ad Soyad / Temsilci Bilgileri' => 'ALAN TURING', 'T.C. Kimlik No' => $this->identity('223456789'), 'Durum' => 'Pasif', 'Pasif Olma Tarihi' => '01.03.2024', 'Pasif Olma Nedeni' => 'İstifa']),
            $this->row(['Ad Soyad / Temsilci Bilgileri' => 'HATALI KİŞİ', 'T.C. Kimlik No' => '12345678901']),
        ]);

        $contacts = Contact::count();
        $this->actingAs($manager)->post('/admin/memberships/import', ['file' => $file, 'fields' => ['profession' => $profession->id]])
            ->assertRedirect('/admin/memberships/import/preview');

        $this->actingAs($manager)->get('/admin/memberships/import/preview')->assertOk()
            ->assertSee('Mevcut kişiye üyelik')
            ->assertSee('Yeni kişi ve üyelik')
            ->assertSee('T.C. kimlik no geçersiz')
            ->assertSee('Listede olmayan aktif üyeler')
            ->assertSee('Liste Dışı')
            ->assertSee('Cinsiyet: <span class="text-secondary">—</span> → Kadın', false);
        $this->assertSame($contacts, Contact::count(), 'The preview writes nothing.');

        $this->actingAs($manager)->post('/admin/memberships/import/apply')->assertRedirect('/admin/memberships')
            ->assertSessionHas('success-status', fn ($message) => str_contains($message, '1 yeni kişi') && str_contains($message, '1 atlanan'));

        // Matched by T.C. kimlik no: empty fields filled, member from the DERBİS date.
        $existing->refresh();
        $this->assertSame('5321234567', $existing->phone);
        $this->assertSame('1985-12-10', $existing->birthday->toDateString());
        $membership = Membership::where('contact_id', $existing->id)->sole();
        $this->assertTrue($membership->isActive() && $membership->derbis_registered);
        $this->assertNull($membership->number);
        $this->assertSame('2018-07-02', $membership->joined_at->toDateString());
        $this->assertTrue($existing->hasAffiliation('member'));
        $this->assertSame('female', $existing->gender);
        $this->assertSame('Bilgisayar Mühendisi', $existing->customFieldValues()->where('custom_field_id', $profession->id)->value('value'));
        $this->assertSame('male', Contact::where('email', 'ismail@ornek.test')->value('gender'));

        // Matched by e-mail; the name already there is kept.
        $byEmail->refresh();
        $this->assertSame($this->identity('323456789'), $byEmail->identity_number);
        $this->assertSame('Grace', $byEmail->first_name);
        $this->assertTrue(Membership::where('contact_id', $byEmail->id)->sole()->isActive());

        // New contact, names in Turkish title case.
        $new = Contact::where('identity_number', $this->identity('423456789'))->sole();
        $this->assertSame(['İsmail', 'Işık', 'ismail@ornek.test'], [$new->first_name, $new->last_name, $new->email]);
        $this->assertNull($new->user);

        // Pasif in DERBİS: the membership ends and the affiliation with it.
        $left = Membership::where('contact_id', $leaving->id)->sole();
        $this->assertSame(Membership::LEFT, $left->status);
        $this->assertSame('2024-03-01', $left->left_at->toDateString());
        $this->assertFalse($leaving->fresh()->hasAffiliation('member'));

        $this->assertFalse(Contact::where('last_name', 'Kişi')->exists());
        $this->assertTrue(Membership::where('contact_id', $notListed->id)->sole()->isActive());
        $this->assertSame([], Storage::disk('local')->files('imports/derbis'));
    }

    public function test_account_holders_keep_their_details_and_get_no_number(): void
    {
        $manager = $this->manager();
        Membership::create(['contact_id' => Contact::create(['first_name' => 'X', 'last_name' => 'Y'])->id, 'number' => '41']);
        $user = User::factory()->create(['name' => 'Linus', 'surname' => 'Torvalds', 'email' => 'linus@ornek.test', 'national_id' => $this->identity('523456789'), 'phone_number' => '5550000000']);

        $file = $this->csv([
            $this->row(['Ad Soyad / Temsilci Bilgileri' => 'LİNUS BENEDİCT TORVALDS', 'T.C. Kimlik No' => $this->identity('523456789'), 'E-Posta' => 'baska@ornek.test', 'Telefon No' => '5551111111']),
        ]);

        $this->actingAs($manager)->post('/admin/memberships/import', ['file' => $file, 'overwrite' => '1']);
        $this->actingAs($manager)->post('/admin/memberships/import/apply');

        $user->refresh();
        $this->assertSame(['Linus', 'linus@ornek.test', '5550000000'], [$user->name, $user->email, $user->phone_number]);
        $this->assertSame('linus@ornek.test', $user->contact->email);
        $this->assertNull(Membership::where('contact_id', $user->contact_id)->value('number'));
    }

    public function test_a_file_without_the_derbis_columns_is_refused(): void
    {
        $file = UploadedFile::fake()->createWithContent('liste.csv', "Ad;Soyad\nAda;Lovelace\n");

        $this->actingAs($this->manager())->post('/admin/memberships/import', ['file' => $file])
            ->assertSessionHasErrors('file');
        $this->assertSame([], Storage::disk('local')->files('imports/derbis'));
    }

    public function test_cancel_deletes_the_file(): void
    {
        $manager = $this->manager();
        $file = $this->csv([$this->row(['Ad Soyad / Temsilci Bilgileri' => 'ADA LOVELACE', 'T.C. Kimlik No' => $this->identity('123456789')])]);
        $this->actingAs($manager)->post('/admin/memberships/import', ['file' => $file]);
        $this->assertCount(1, Storage::disk('local')->files('imports/derbis'));

        $this->actingAs($manager)->delete('/admin/memberships/import')->assertRedirect('/admin/memberships/import');
        $this->assertSame([], Storage::disk('local')->files('imports/derbis'));
        $this->actingAs($manager)->get('/admin/memberships/import/preview')->assertRedirect('/admin/memberships/import');
    }

    public function test_xlsx_files_are_read_without_the_zip_extension(): void
    {
        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="inlineStr"><is><t>T.C. Kimlik No</t></is></c></row>'
            .'<row r="3"><c r="A3" t="s"><v>1</v></c><c r="B3"><v>43283.75</v></c><c r="C3"><v>51517467220</v></c></row>'
            .'</sheetData></worksheet>';
        $files = [
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Liste" sheetId="1" r:id="rId2"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Üye Niteliği</t></si><si><r><t>Ger</t></r><r><t>çek</t></r></si></sst>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $this->zip($files));

        $rows = (new SpreadsheetReader)->read($path, 'xlsx');
        unlink($path);

        $this->assertSame(['Üye Niteliği', null, 'T.C. Kimlik No'], $rows[0]);
        $this->assertSame([], $rows[1]);
        $this->assertSame(['Gerçek', '43283.75', '51517467220'], $rows[2]);
        $this->assertSame('2018-07-02', SpreadsheetReader::excelDate(43283.75));
        $this->assertTrue(DerbisImport::validIdentityNumber($this->identity('123456789')));
        $this->assertFalse(DerbisImport::validIdentityNumber('12345678901'));
    }

    /** A deflated zip archive, as spreadsheet programs write it. */
    private function zip(array $files): string
    {
        $data = $directory = '';
        foreach ($files as $name => $content) {
            $deflated = gzdeflate($content);
            $header = pack('vvvvvVVVvv', 20, 0, 8, 0, 0, crc32($content), strlen($deflated), strlen($content), strlen($name), 0);
            $directory .= "PK\x01\x02".pack('v', 20).$header.pack('vvvVV', 0, 0, 0, 0, strlen($data)).$name;
            $data .= "PK\x03\x04".$header.$name.$deflated;
        }

        return $data.$directory."PK\x05\x06".pack('vvvvVVv', 0, 0, count($files), count($files), strlen($directory), strlen($data), 0);
    }
}
