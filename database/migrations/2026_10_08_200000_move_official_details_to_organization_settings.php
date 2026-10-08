<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The registry and MERSİS numbers belong to the organization, not to the correspondence module. */
    private const KEYS = [
        'correspondence_registry_no' => 'registry_no',
        'correspondence_identifier' => 'mersis_no',
    ];

    public function up(): void
    {
        $this->rename(self::KEYS);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::KEYS));
    }

    /**
     * @param  array<string, string>  $keys  old key => new key
     */
    private function rename(array $keys): void
    {
        foreach ($keys as $old => $new) {
            DB::table('settings')->where('key', $new)->exists()
                ? DB::table('settings')->where('key', $old)->delete()
                : DB::table('settings')->where('key', $old)->update(['key' => $new]);
        }
    }
};
