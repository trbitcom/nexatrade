<?php

declare(strict_types=1);

// Veritabani baglanti ayarlari ve sifreleme anahtari - SABLON dosya, gercek degerler icermez.
// VPS'e ilk kurulumda: bu dosyayi database.php olarak kopyalayip gercek degerleri doldurun
// (database.php .gitignore'da, repoya asla girmez - bkz. gecis plani)
//
//   cp config/database.example.php config/database.php

return [
    'db' => [
        'host'    => 'localhost',
        'dbname'  => 'nexatrade',
        'username' => '',
        'password' => '',
        'charset' => 'utf8mb4',
    ],

    // Borsa API anahtarlarini (api_key, secret_key) sifrelemek/cozmek icin kullanilir
    // AES-256-CBC ile uyumlu olmasi icin tam 32 karakter uzunlugunda olmalidir
    // Uretmek icin: php -r "echo bin2hex(random_bytes(16));"
    'encryption_key' => '',
];
