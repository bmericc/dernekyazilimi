<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // female, male; kept on the contact (not the account), so the
        // profile, membership applications and imports share it.
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->after('birthday');
        });

        $this->backfill();
    }

    /**
     * Gender already known: the latest membership application of the
     * contact, then a "Cinsiyet" custom field.
     */
    private function backfill(): void
    {
        if (Schema::hasTable('membership_applications')) {
            DB::table('membership_applications')->orderBy('id')->get(['contact_id', 'data'])
                ->each(function ($application) {
                    $gender = json_decode((string) $application->data, true)['gender'] ?? null;
                    if (in_array($gender, ['female', 'male'], true)) {
                        DB::table('contacts')->where('id', $application->contact_id)->update(['gender' => $gender]);
                    }
                });
        }

        $fields = DB::table('custom_fields')->where(fn ($query) => $query->whereIn('key', ['cinsiyet', 'gender'])->orWhere('label', 'Cinsiyet'))->pluck('id');
        if ($fields->isEmpty()) {
            return;
        }
        $genders = ['kadın' => 'female', 'kadin' => 'female', 'k' => 'female', 'female' => 'female', 'erkek' => 'male', 'e' => 'male', 'male' => 'male'];
        DB::table('custom_field_values')->whereIn('custom_field_id', $fields)->orderBy('id')->get(['contact_id', 'value'])
            ->each(function ($value) use ($genders) {
                $gender = $genders[mb_strtolower(strtr(trim((string) $value->value), ['I' => 'ı', 'İ' => 'i']))] ?? null;
                if ($gender) {
                    DB::table('contacts')->where('id', $value->contact_id)->whereNull('gender')->update(['gender' => $gender]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
