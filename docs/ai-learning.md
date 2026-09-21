# Central de Aprendizado da IA (branch experimental)

Este módulo fornece **memória contextual de exemplos corrigidos**, não faz
fine-tuning, não altera os pesos do Llama e não executa apostas.

## Arquivos

- app/AiLearningMemory.php — tabela de exemplos, revisão com auditoria, recuperação
  limitada a 3 exemplos aprovados por similaridade lexical e escopo da regra.
- ai-learning.php — página administrativa com autenticação, CSRF, cadastro de
  print/legenda, campos estruturados, aprovação, correção, rejeição e preview
  protegido de imagens. Não publica nem encaminha mensagem.
- app/SmartFormatting.php — anexa exemplos aprovados aos prompts Gemini e
  Workers AI somente com AI_LEARNING_ENABLED=1. Preserve a chamada ao
  VipCardRenderer já existente; nenhuma imagem/card existente é redesenhado.
- index.php — acrescenta link de navegação; não substitui páginas existentes.
- tests/ai-learning-memory-test.php — invariantes de recuperação sem banco.
- .github/workflows/ai-learning-checks.yml — lint e testes isolados.

## Fluxo operacional proposto

1. Com o módulo instalado, abra /ai-learning.php autenticado.
2. Envie texto/transcrição e/ou print, preencha confronto, mercado, seleção
   e demais campos. Um exemplo novo fica PENDENTE.
3. Aprove/revise ou rejeite. Somente exemplos APROVADOS participam da memória.
4. Habilite AI_LEARNING_ENABLED=1 apenas depois de validar compatibilidade com
   a versão real em execução e testar o fluxo de IA em ambiente isolado.
5. Cada tip com texto é comparada com no máximo 250 exemplos aprovados e recebe
   até 3 exemplos de contexto; imagens sem texto/transcrição não são recuperadas
   por comparação visual neste módulo inicial.
6. Desative AI_LEARNING_ENABLED para retornar aos prompts sem memória sem remover
   qualquer exemplo armazenado.

## Proteção da produção / limitação do source-of-truth

O Railway intelligent-rebirth / telegramrouter executa um startCommand que
reconstrói parte do código-fonte a partir de várias variáveis de ambiente e
aplica patches posteriores ao checkout do GitHub. Logo, os arquivos
app/SmartFormatting.php e index.php presentes na branch NÃO são necessariamente
os arquivos efetivos em produção. Esta branch NÃO deve ser implantada sem:

- reconciliar o source snapshot efetivo com o diff deste PR;
- incorporar apenas as mudanças deste módulo nas partes correspondentes do
  runtime, respeitando a precedência dos patches existentes;
- instalar os arquivos novos na imagem final e preservar o volume storage;
- executar testes de regressão dos cards, tradução, fallback, encaminhamento,
  responsividade e fluxos de edição/recebimento, incluindo casos com memória
  desativada/ativada; e
- testar numa instância isolada antes de ativar o recurso em produção.

Nenhuma alteração de Railway, variáveis de ambiente ou deploy é necessária
para revisar este PR. **A abertura deste PR não habilita o recurso.**

## Segurança, retenção e rótulos

Somente administradores autenticados podem acessar o módulo e as imagens.
CSRF é verificado em todas as escritas. Uploads aceitam somente PNG/JPEG/WebP
de até 4 MB e ficam no diretório storage/ai-learning. O armazenamento deve
persistir no volume da aplicação e não ser público. Imagens originais e texto
dos exemplos NÃO são enviados a outro provedor pelo módulo; o prompt de memória
recebe trechos de texto + campos estruturados somente dos exemplos aprovados.
O modelo ainda pode desobedecer instruções; o validador e o gerador de cards
existentes continuam responsáveis pelo resultado final.

Antes de inserir prints, remova saldos, contas, telefones e dados pessoais que
não sejam estritamente necessários. Não aprove exemplos gerados artificialmente
sem checagem do texto e dos campos. Planeje futuramente paginação completa,
revisão de retenção/exclusão, controle RBAC granular e avaliação de acurácia
por mercado, linha, odd e seleção com uma base real separada.
