# Backup de recuperação — TelegramRouter

Este diretório contém um snapshot sanitizado do código que estava em produção no serviço `telegramrouter` do projeto Railway `intelligent-rebirth`.

## Conteúdo

- `production-source-2026-09-18.part01.b64` até `part09.b64`: snapshot completo do filesystem da aplicação em produção, compactado em `.tar.gz` e dividido em partes Base64.
- `railway-start-command.txt`: comando de inicialização do serviço, sem valores secretos.
- `railway-variable-names.txt`: nomes das variáveis necessárias no ambiente Railway, sem valores.
- `production-runtime-manifest.txt`: referência do snapshot e do estado validado.

O snapshot foi criado excluindo itens que não devem ir para o GitHub: `.env`, `vendor/`, `storage/`, logs, arquivo de healthcheck e o endpoint temporário usado para exportação.

## Como reconstruir o arquivo do snapshot

Linux/macOS:

```bash
cat production-source-2026-09-18.part*.b64 | tr -d '\r\n' | base64 -d > production-source-2026-09-18.tar.gz
mkdir restored
tar -xzf production-source-2026-09-18.tar.gz -C restored
```

Windows PowerShell pode concatenar os arquivos em ordem e decodificar o Base64 antes de extrair o `.tar.gz`.

Depois de extrair:

```bash
cd restored
composer install --optimize-autoloader --no-scripts --no-interaction
```

## Segredos e dados persistentes

Por segurança, valores sensíveis **não** estão versionados. Para uma restauração real, também é necessário recuperar de um cofre/backup seguro os valores das variáveis de ambiente (banco, Telegram, Gemini etc.) e restaurar separadamente qualquer dado persistente do volume/ banco de dados.

O objetivo deste diretório é garantir que o código real da aplicação em produção permaneça recuperável mesmo em caso de perda do servidor.
