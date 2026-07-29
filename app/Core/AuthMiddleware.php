<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

// Giris yapilmamis kullanicilarin korumali sayfalara erismesini engeller
final class AuthMiddleware
{
    public static function handle(): void
    {
        Session::start();

        if (!Session::has('user_id')) {
            header('Location: ' . Url::to('/login'));
            exit;
        }

        // 16 Temmuz'da tespit edildi: bu kontrol SADECE session'da user_id olup olmadigina
        // bakiyordu - bir admin kullaniciyi banladiktan/pasife aldiktan SONRA bile, o kullanicinin
        // ZATEN acik oturumu gecerli kalmaya devam ediyordu (oturum dogal olarak sonlanana/cikis
        // yapana kadar API anahtarlarini/otomatik islem ayarlarini degistirmeye devam edebilirdi) -
        // "banlama" bir acil-durdurma kontrolu olarak fiilen calismiyordu. Artik HER istekte
        // veritabanindan taze durum okunur; artik 'active' degilse oturum hemen sonlandirilir
        $user = User::findById((int) Session::get('user_id'));

        if ($user === false || $user['status'] !== 'active') {
            Session::destroy();
            header('Location: ' . Url::to('/login'));
            exit;
        }
    }

    // Giris kontrolune ek olarak, sadece role='admin' olan kullanicilarin gecmesine izin verir
    public static function requireAdmin(): void
    {
        self::handle();

        if (Session::get('user_role') !== 'admin') {
            header('Location: ' . Url::to('/dashboard'));
            exit;
        }
    }
}
