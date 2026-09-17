# Telegram Media Router em PHP

Aplicação PHP para monitorar uma conta de usuário do Telegram, aplicar regras de limpeza, tradução e encaminhamento de texto e mídia para outros chats.

> A interface operacional apresenta rótulos amigáveis em português, mantendo os identificadores técnicos internos intactos para diagnóstico.

## Requisitos

O servidor precisa ter PHP 8.2 ou superior, MySQL ou MariaDB, Composer ou a pasta `vendor` já incluída, além das extensões `pdo_mysql`, `openssl`, `mbstring`, `curl`, `json`, `gmp`, `xml`, `fileinfo` e `iconv`. O projeto não possui pasta `public`: o document root deve apontar diretamente para a pasta onde estão `index.php`, `login.php` e `install.php`.

## Instalação pelo navegador

Envie todos os arquivos do projeto para o servidor e aponte o domínio ou subdomínio diretamente para a raiz do projeto. Se a hospedagem permitir, mantenha a pasta fora do diretório público e configure o document root para ela. A pasta `vendor` já acompanha o pacote, portanto não é obrigatório executar Composer no servidor.

Abra:

```text
https://seu-dominio.com/install.php
```

O instalador permite informar o servidor, porta, banco, usuário e senha do banco; usuário e senha iniciais do painel; endereço da aplicação; API ID e chave da API do Telegram. Ele gera automaticamente `APP_KEY`, cria as tabelas do sistema e grava as credenciais no arquivo `.env` com permissão restrita. O usuário do banco precisa ter permissão para criar o banco caso ele ainda não exista; se o banco já estiver criado, basta ter permissão para criar tabelas.

Depois da instalação, acesse `login.php`, entre com o usuário criado e conecte a conta Telegram em **Conectar Telegram**. Por segurança, remova ou bloqueie `install.php` após a conclusão. O instalador também ficará bloqueado enquanto `.env` existir.

## Instalação manual alternativa

Copie `.env.example` para `.env`, preencha os dados do banco, gere a chave da aplicação e o hash da senha:

```bash
cp .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php scripts/generate-password.php 'SUA_SENHA'
```

Importe `database/schema.sql` no banco e acesse `login.php`.

## Cron na Umbler

Na Umbler, abra o painel do site, entre em **Avançado**, **Cron Jobs** ou **Tarefas agendadas** — o nome pode variar conforme o tipo de hospedagem — e crie uma tarefa para executar o trabalhador.

Use o caminho absoluto da instalação. Exemplo:

```cron
* * * * * cd /home/usuario/telegram-router && /usr/bin/php worker.php >> storage/worker.log 2>&1
```

Se o caminho do PHP for diferente, descubra-o pelo terminal SSH com `which php`. Quando a Umbler permitir a execução contínua de processos, prefira `php worker.php` sob Supervisor ou outro gerenciador; no plano compartilhado, o cron é o mecanismo de recuperação recomendado.

### Qual intervalo usar?

Para receber mensagens com baixa demora, use **a cada 1 minuto** (`* * * * *`). Esse é o intervalo recomendado quando a Umbler não mantém um processo PHP contínuo. O cron de 1 minuto pode gerar uma nova execução enquanto a anterior ainda está ativa; por isso, o trabalhador deve usar um bloqueio de processo ou a hospedagem deve impedir execuções simultâneas.

Se a hospedagem não aceitar tarefas a cada minuto, use **a cada 5 minutos** (`*/5 * * * *`), sabendo que o encaminhamento poderá atrasar até cinco minutos. Para roteamento em tempo quase real, use um processo persistente em VPS, Supervisor ou serviço equivalente.

Após autenticar pelo painel, o sistema tenta iniciar `worker.php` automaticamente. O cron continua sendo o fallback para hospedagens que bloqueiam `proc_open`, processos em segundo plano ou conexões persistentes.

## Proteção e permissões

O `.htaccess` da raiz bloqueia acesso direto às pastas `app`, `config`, `database`, `scripts`, `storage` e `vendor`. Mantenha `storage` gravável pelo usuário do PHP e restrinja o arquivo `.env` para leitura do usuário do servidor. Nunca publique o `.env`, a sessão Telegram ou os logs em um repositório.

## Funcionalidades

O projeto inclui painel responsivo, login com sessão e CSRF, regras de origem e destino, modos de texto e mídia, limpeza controlável de links e emojis, preservação de formatação Telegram, tradução opcional, atividades paginadas, atualização assíncrona, conexão de usuário Telegram e trabalhador CLI para cron ou processo persistente.

## Integração com Google Gemini AI Studio

Depois de entrar no painel, abra **Integrações**, informe a chave da API do Google AI Studio e selecione o modelo disponível. A chave é cifrada com AES-256-GCM antes de ser armazenada no banco e não fica exposta na tela. Para trocar a chave, basta informar uma nova no mesmo módulo; não é necessário reinstalar nem editar `.env`.

Nas regras de roteamento, selecione **Google Gemini** e marque **Ativar tradução**. Defina o idioma de destino, por exemplo `pt-BR`. Se a chamada à API falhar e a opção de fallback estiver ativa, a mensagem original será preservada.
