<?php declare(strict_types=1);
namespace App;
final class Crypto {
    private static function key(): string { $key=(string)config('app_key'); if ($key === '') throw new \RuntimeException('APP_KEY não configurada.'); return hash('sha256',$key,true); }
    public static function encrypt(string $plain): string { $iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($plain,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag); if ($cipher===false) throw new \RuntimeException('Falha ao cifrar.'); return base64_encode($iv.$tag.$cipher); }
    public static function decrypt(?string $encoded): string { if (!$encoded) return ''; $raw=base64_decode($encoded,true); if ($raw===false || strlen($raw)<28) return ''; $iv=substr($raw,0,12); $tag=substr($raw,12,16); $cipher=substr($raw,28); $plain=openssl_decrypt($cipher,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag); return $plain===false?'':$plain; }
}
