<?php declare(strict_types=1);
namespace App;
final class Auth {
    public static function boot(): void { if (session_status() !== PHP_SESSION_ACTIVE) { session_name('tmr_session'); session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','samesite'=>'Lax','path'=>'/']); session_start(); } }
    public static function login(string $user, string $password): bool { self::boot(); if (!hash_equals((string)config('panel_username'), $user)) return false; $hash=(string)config('panel_password_hash'); if ($hash === '' || !password_verify($password, $hash)) return false; session_regenerate_id(true); $_SESSION['authenticated']=true; $_SESSION['csrf']=bin2hex(random_bytes(32)); return true; }
    public static function logout(): void { self::boot(); $_SESSION=[]; if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',$p['secure'],$p['httponly']); } session_destroy(); }
    public static function check(): bool { self::boot(); return !empty($_SESSION['authenticated']); }
    public static function requireLogin(): void { if (!self::check()) { header('Location: /login.php'); exit; } }
    public static function csrf(): string { self::boot(); return (string)($_SESSION['csrf'] ??= bin2hex(random_bytes(32))); }
    public static function verifyCsrf(?string $token): void { if (!$token || !hash_equals(self::csrf(), $token)) { http_response_code(419); exit('Token de segurança inválido.'); } }
}
