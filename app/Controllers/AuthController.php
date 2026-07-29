<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Session;
use App\Core\Url;
use App\Models\User;
use Throwable;

final class AuthController
{
    public function showLogin(): void
    {
        Session::start();

        if (Session::has('user_id')) {
            header('Location: ' . Url::to('/dashboard'));
            return;
        }

        $error = Session::get('login_error');
        $success = Session::get('login_success');
        Session::remove('login_error');
        Session::remove('login_success');

        require __DIR__ . '/../Views/auth/login.php';
    }

    public function showRegister(): void
    {
        Session::start();

        if (Session::has('user_id')) {
            header('Location: ' . Url::to('/dashboard'));
            return;
        }

        $error = Session::get('register_error');
        Session::remove('register_error');

        require __DIR__ . '/../Views/auth/register.php';
    }

    public function register(): void
    {
        Session::start();

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            Session::set('register_error', 'Lütfen tüm alanları doldurun.');
            header('Location: ' . Url::to('/register'));
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::set('register_error', 'Geçerli bir e-posta adresi girin.');
            header('Location: ' . Url::to('/register'));
            return;
        }

        if (strlen($password) < 8) {
            Session::set('register_error', 'Şifre en az 8 karakter olmalıdır.');
            header('Location: ' . Url::to('/register'));
            return;
        }

        if ($password !== $passwordConfirm) {
            Session::set('register_error', 'Şifreler eşleşmiyor.');
            header('Location: ' . Url::to('/register'));
            return;
        }

        if (User::findByEmail($email) !== false) {
            Session::set('register_error', 'Bu e-posta adresi zaten kayıtlı.');
            header('Location: ' . Url::to('/register'));
            return;
        }

        try {
            User::create($name, $email, $password);
        } catch (Throwable $e) {
            Session::set('register_error', 'Kayıt sırasında bir hata oluştu.');
            header('Location: ' . Url::to('/register'));
            return;
        }

        // Hesap 'passive' (onay bekliyor) olarak olusturulur - admin onaylamadan oturum acilmaz,
        // bu yuzden burada otomatik giris YAPILMAZ, kullanici giris sayfasina bilgilendirilerek yonlendirilir
        Session::set('login_success', 'Kaydınız alındı. Hesabınız yönetici onayından sonra aktif olacaktır.');
        header('Location: ' . Url::to('/login'));
    }

    public function login(): void
    {
        Session::start();

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $user = User::findByEmail($email);

        if ($user === false || !User::verifyPassword($password, $user['password'])) {
            Session::set('login_error', 'E-posta veya şifre hatalı.');
            header('Location: ' . Url::to('/login'));
            return;
        }

        if ($user['status'] === 'passive') {
            Session::set('login_error', 'Hesabınız henüz onaylanmadı. Lütfen yönetici onayını bekleyin.');
            header('Location: ' . Url::to('/login'));
            return;
        }

        if ($user['status'] === 'banned') {
            Session::set('login_error', 'Hesabınız engellenmiştir.');
            header('Location: ' . Url::to('/login'));
            return;
        }

        // Session sabitleme (session fixation) saldirilarina karsi kimlik dogrulama sonrasi ID yenilenir
        session_regenerate_id(true);

        Session::set('user_id', (int) $user['id']);
        Session::set('user_name', $user['name']);
        Session::set('user_role', $user['role'] ?? 'user');

        header('Location: ' . Url::to('/dashboard'));
    }

    public function logout(): void
    {
        Session::destroy();
        header('Location: ' . Url::to('/login'));
    }
}
