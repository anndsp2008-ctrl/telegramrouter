<?php declare(strict_types=1);
namespace App;

final class ErrorTranslator
{
    public static function message(\Throwable $error, string $operation = 'a operação'): string
    {
        $raw = mb_strtolower($error->getMessage(), 'UTF-8');
        $cause = match (true) {
            str_contains($raw, 'peer is not present') || str_contains($raw, 'peer database') => 'o canal de destino não está acessível pela conta Telegram ou ainda não foi carregado',
            str_contains($raw, 'media_caption_too_long') || str_contains($raw, 'caption too long') => 'a legenda da mídia excedeu o limite permitido pelo Telegram; o sistema dividirá o texto em uma mensagem complementar',
            str_contains($raw, 'flood') => 'o Telegram impôs um limite temporário de frequência',
            str_contains($raw, 'not enough rights') || str_contains($raw, 'forbidden') => 'a conta não possui permissão suficiente no canal de destino',
            str_contains($raw, 'unauthorized') || str_contains($raw, 'auth key') => 'a sessão da conta Telegram não está autorizada',
            str_contains($raw, 'timeout') || str_contains($raw, 'timed out') => 'a conexão excedeu o tempo limite',
            str_contains($raw, 'connection') || str_contains($raw, 'socket') || str_contains($raw, 'network') => 'a conexão com o Telegram foi interrompida',
            str_contains($raw, 'invalid api') || str_contains($raw, 'api_id') || str_contains($raw, 'api hash') => 'as credenciais da aplicação Telegram são inválidas',
            str_contains($raw, 'phone') && str_contains($raw, 'invalid') => 'o número de telefone informado é inválido',
            str_contains($raw, 'password') => 'a senha de verificação em duas etapas foi rejeitada',
            str_contains($raw, 'code') && str_contains($raw, 'invalid') => 'o código de confirmação informado é inválido ou expirou',
            default => 'não foi possível determinar a causa exata; a tentativa foi interrompida',
        };
        return 'Não foi possível concluir '.$operation.' porque '.$cause.'.';
    }
}
