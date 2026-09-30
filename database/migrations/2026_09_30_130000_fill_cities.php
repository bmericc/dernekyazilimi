<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Turkish provinces and the TRNC cities (id = plate number), as in LKD's
     * list. Installations whose city table is empty (it was only filled by
     * the CitiesTableSeeder by hand) get them; a filled table is left alone.
     */
    private const CITIES = [
        [1, 'Adana', 1, 322],
        [2, 'Adıyaman', 2, 416],
        [3, 'Afyon', 3, 272],
        [4, 'Ağrı', 4, 472],
        [5, 'Amasya', 5, 358],
        [6, 'Ankara', 6, 312],
        [7, 'Antalya', 7, 242],
        [8, 'Artvin', 8, 466],
        [9, 'Aydın', 9, 256],
        [10, 'Balıkesir', 10, 266],
        [11, 'Bilecik', 11, 228],
        [12, 'Bingöl', 12, 426],
        [13, 'Bitlis', 13, 434],
        [14, 'Bolu', 14, 374],
        [15, 'Burdur', 15, 248],
        [16, 'Bursa', 16, 224],
        [17, 'Çanakkale', 17, 286],
        [18, 'Çankırı', 18, 376],
        [19, 'Çorum', 19, 364],
        [20, 'Denizli', 20, 258],
        [21, 'Diyarbakır', 21, 412],
        [22, 'Edirne', 22, 284],
        [23, 'Elazığ', 23, 424],
        [24, 'Erzincan', 24, 446],
        [25, 'Erzurum', 25, 442],
        [26, 'Eskişehir', 26, 222],
        [27, 'Gaziantep', 27, 342],
        [28, 'Giresun', 28, 454],
        [29, 'Gümüşhane', 29, 456],
        [30, 'Hakkari', 30, 438],
        [31, 'Hatay', 31, 326],
        [32, 'Isparta', 32, 246],
        [33, 'Mersin', 33, 324],
        [34, 'İstanbul', 34, 212],
        [35, 'İzmir', 35, 232],
        [36, 'Kars', 36, 474],
        [37, 'Kastamonu', 37, 366],
        [38, 'Kayseri', 38, 352],
        [39, 'Kırklareli', 39, 288],
        [40, 'Kırşehir', 40, 386],
        [41, 'Kocaeli', 41, 262],
        [42, 'Konya', 42, 332],
        [43, 'Kütahya', 43, 274],
        [44, 'Malatya', 44, 422],
        [45, 'Manisa', 45, 236],
        [46, 'Kahramanmaraş', 46, 344],
        [47, 'Mardin', 47, 482],
        [48, 'Muğla', 48, 252],
        [49, 'Muş', 49, 436],
        [50, 'Nevşehir', 50, 384],
        [51, 'Niğde', 51, 388],
        [52, 'Ordu', 52, 452],
        [53, 'Rize', 53, 464],
        [54, 'Sakarya', 54, 264],
        [55, 'Samsun', 55, 362],
        [56, 'Siirt', 56, 484],
        [57, 'Sinop', 57, 368],
        [58, 'Sivas', 58, 346],
        [59, 'Tekirdağ', 59, 282],
        [60, 'Tokat', 60, 356],
        [61, 'Trabzon', 61, 462],
        [62, 'Tunceli', 62, 428],
        [63, 'Şanlıurfa', 63, 414],
        [64, 'Uşak', 64, 276],
        [65, 'Van', 65, 432],
        [66, 'Yozgat', 66, 354],
        [67, 'Zonguldak', 67, 372],
        [68, 'Aksaray', 68, 382],
        [69, 'Bayburt', 69, 458],
        [70, 'Karaman', 70, 338],
        [71, 'Kırıkkale', 71, 318],
        [72, 'Batman', 72, 488],
        [73, 'Şırnak', 73, 486],
        [74, 'Bartın', 74, 378],
        [75, 'Ardahan', 75, 478],
        [76, 'Iğdır', 76, 476],
        [77, 'Yalova', 77, 226],
        [78, 'Karabük', 78, 370],
        [79, 'Kilis', 79, 348],
        [80, 'Osmaniye', 80, 328],
        [81, 'Düzce', 81, 380],
        [100, 'Lefkoşa, KKTC', 100, 0],
        [101, 'Girne, KKTC', 101, 0],
        [102, 'Gazimagusa, KKTC', 102, 0],
        [103, 'Güzelyurt, KKTC', 103, 0],
        [104, 'İskele, KKTC', 104, 0],
    ];

    public function up(): void
    {
        if (DB::table('cities')->exists()) {
            return;
        }

        $now = now();
        DB::table('cities')->insert(array_map(fn (array $city) => [
            'id' => $city[0], 'city_name' => $city[1], 'city_plate_no' => $city[2], 'city_phone_code' => $city[3],
            'status' => 1, 'created_at' => $now, 'updated_at' => $now,
        ], self::CITIES));
    }

    public function down(): void
    {
        // The cities stay: accounts and contacts point to them.
    }
};
