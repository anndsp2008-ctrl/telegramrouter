# TelegramRouter — versão independente para deploy

Este diretório contém uma versão **sem credenciais**, montada a partir do snapshot sanitizado do código operacional e dos módulos atuais do repositório. Não precisa de variáveis `*_B64` nem do comando de inicialização legado do serviço original.

## Novo serviço Railway

1. Crie **um novo serviço** conectado ao repositório GitHub. Não substitua o comando ou as variáveis do serviço `telegramrouter` já em operação.
2. Em **Settings → Source**, configure **Root Directory** como `/release`. Use Railpack e o comando `sh start.sh` (o arquivo `railway.json` desta pasta define essas opções).
3. Configure um MySQL persistente e o volume persistente montado em `/app/storage`. **Não utilize o mesmo volume de sessão Telegram em duas instâncias simultâneas.**
4. Configure no Railway, em **Variables**, `APP_URL`, `APP_KEY`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `PANEL_USERNAME` e `PANEL_PASSWORD_HASH`. Use o arquivo `.env.example` apenas como lista de nomes; não publique os valores. Gere `APP_KEY` localmente com `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`; gere o hash do usuário pelo instalador/CLI.
5. Defina `RAILPACK_PHP_EXTENSIONS=json openssl mbstring pdo_mysql curl gmp xml fileinfo iconv gd`. Ajuste `APP_URL` ao domínio real e configure as credenciais do Telegram e das IAs **no painel** ou por variáveis privadas. Não copie `.env`, sessões, bancos de dados ou logs para o GitHub.
6. Rode o instalador `/install.php` quando estiver configurando uma base nova, ou restaure **separadamente**, de backup privado, o banco e o volume de uma instância existente. A aplicação não transfere contas, regras nem sessões pelo código.
7. Verifique `/health`, faça login, conecte o Telegram e teste uma regra de encaminhamento em ambiente isolado antes de direcionar tráfego real.

O script `start.sh` falha de forma explícita se as variáveis obrigatórias estiverem ausentes. `Caddyfile` bloqueia acesso HTTP a código-fonte, banco, variáveis, logs e diretórios internos, preservando as rotas de API previstas.

**Nota:** o código do snapshot sanitizado é de 18–19/09/2026 e os módulos versionados desta branch incluem mudanças posteriores. A equivalência integral com todos os artefatos injetados apenas por variáveis na instância anterior exige comparação adicional de runtime; a instância original não é alterada por esta preparação.
