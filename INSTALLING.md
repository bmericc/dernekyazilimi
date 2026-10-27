# Kurulum Aşamaları

* terminal açılır

* projenin kurulu olduğu klasöre giriş yapılır

* ```cp .env.example .env```

* ```.env``` dosyasının içi (başta veritabanı bilgileri olmak üzere) düzenlenir 

* ```composer install```

* ```php artisan migrate```

* ```php artisan db:seed --class CitiesTableSeeder```

* ```php artisan db:seed --class UniversitiesTableSeeder```

* çalıştırmak için de ```php artisan serv```


## Yazışma PDF desteği

PDF 1.5 ve sonrasında kullanılan sıkıştırılmış nesneleri açmak için sunucuda `qpdf` bulunmalıdır (Debian/Ubuntu: `apt-get install qpdf`, macOS: `brew install qpdf`). Portal yalnız imzasız asıl yazının okuma kopyasını dönüştürür; yüklenen özgün dosya ve imzalı ekler olduğu gibi saklanır.
