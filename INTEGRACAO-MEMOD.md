# Roteiro: integrar e testar o champs-onboarding no MEMOD

Este arquivo é um roteiro para o Claude Code. O pacote `champs-onboarding` foi
escrito fora do MEMOD e só passou por `php -l` (sem `composer install` e sem teste
de execução). O objetivo aqui é instalar no MEMOD, corrigir o que quebrar e
validar o backend de ponta a ponta.

## Regras

- Trabalhe numa branch nova no MEMOD (`feature/champs-onboarding`). Não faça commit na main.
- Use **somente** o banco de desenvolvimento/homologação. Nunca rode migration em produção.
- Correções do bundle vão no próprio `champs-onboarding` (é o pacote reutilizável).
  No MEMOD, só configuração, templates e o que for específico dele.
- **Não ligue tour obrigatório** (`mandatory.enabled: false`): o front (`Onboarding.js`)
  ainda não existe e o usuário seria redirecionado sem ver tour nenhum.
- Antes de mudar uma decisão de arquitetura do bundle, pare e pergunte ao Beto.
- Siga as convenções do MEMOD: services/managers, controllers finos, Messenger para assíncrono.

## Estrutura de pastas esperada

```
algum-lugar/
├── memod/               ← projeto Symfony 7.4
└── champs-onboarding/   ← este pacote
```

## Etapa 1 — Reconhecimento (só leitura)

Leia o README.md do pacote e depois levante no MEMOD:

1. Versões de PHP, Symfony, `doctrine/orm`, `doctrine/dbal` e `doctrine/doctrine-bundle`
2. A entidade de usuário: classe, propriedade por trás do `getUserIdentifier()` e propriedade das roles
3. Se existe hierarquia de roles em `security.yaml`
4. A naming strategy do Doctrine (`doctrine.yaml`)
5. Transports do Messenger e como os workers rodam
6. Nome da rota de logout e de outras rotas que nunca devem ser bloqueadas
7. Se o `champs-frontend` já está instalado e como o `app.js` inicializa o `initCore`

Mostre um resumo ao Beto antes de seguir.

## Etapa 2 — Instalação

1. No `composer.json` do MEMOD, adicione o repositório `path`:
   ```json
   { "type": "path", "url": "../champs-onboarding", "options": { "symlink": true } }
   ```
2. `composer require betocampoy/champs-onboarding:@dev`
   - Se `betocampoy/champs-frontend:dev-main` der conflito, ajuste a constraint no
     `composer.json` do pacote para o que o MEMOD já usa.
3. Registre `BetoCampoy\Champs\Onboarding\ChampsOnboardingBundle` em `config/bundles.php`
   (não há recipe Flex).
4. Crie `config/packages/champs_onboarding.yaml`:
   ```yaml
   champs_onboarding:
       mandatory:
           enabled: false
           exempt_routes: [<rota de logout do MEMOD>]
       monitoring:
           user_class: <classe User do MEMOD>
           identifier_property: <propriedade do identifier>
           roles_property: <propriedade das roles>
   ```
5. Crie `config/routes/champs_onboarding.yaml`:
   ```yaml
   champs_onboarding:
       resource: '@ChampsOnboardingBundle/config/routes.php'
       prefix: /onboarding
   ```
6. No `messenger.yaml`, roteie `BetoCampoy\Champs\Onboarding\Message\SyncMonitoredTour`
   para o transport assíncrono do MEMOD.
7. Rode `php bin/console cache:clear` e corrija qualquer erro de container.

## Etapa 3 — Verificações do container

Rode e confira:

```bash
php bin/console debug:router | grep champs_onboarding        # 4 rotas
php bin/console debug:container | grep -i onboarding
php bin/console debug:event-dispatcher kernel.request | grep -i Mandatory
php bin/console debug:messenger | grep -i SyncMonitored
php bin/console list champs                                   # champs:onboarding:sync
php bin/console doctrine:mapping:info | grep -i Onboarding    # 3 entidades
php bin/console doctrine:schema:validate
```

Pontos de risco conhecidos (escrito sem rodar, verificar com atenção):

- `ChampsOnboardingBundle::prependExtension()` registra o mapeamento Doctrine.
  Confirmar que as 3 entidades aparecem e que não conflita com o mapeamento do MEMOD.
- `TourProgressRepository` usa `ArrayParameterType` e `fetchAllKeyValue` (DBAL 3.6+/4).
- `services.php` faz `load()` de `src/` inteiro com exclusões, e depois carrega
  `Controller/` de novo com a tag. Conferir se não há serviço duplicado ou faltando.
- Listeners com `#[AsDoctrineListener]`: confirmar que estão registrados na connection certa.
- `MonitoredUserProvider` depende de `RoleHierarchyInterface` (autowiring).

## Etapa 4 — Banco

1. `php bin/console doctrine:migrations:diff`
2. **Leia a migration gerada.** Ela deve criar só `champs_onboarding_tour`,
   `champs_onboarding_step` e `champs_onboarding_progress`. Se aparecer qualquer
   alteração em tabela do MEMOD, pare e mostre ao Beto.
3. Confira os nomes das colunas (ex.: `trigger_type`, `user_identifier`, `tour_id`).
4. `php bin/console doctrine:migrations:migrate` no banco de dev.

## Etapa 5 — Tour de teste

Ainda não existe CRUD. Crie um comando de dev **no MEMOD** (não no bundle), por
exemplo `app:onboarding:seed-teste`, que cria via Doctrine:

- Tour `teste-memod`, `startRoute` = uma página real e sem parâmetros do MEMOD,
  `active = true`, `trigger = FIRST_ACCESS`, `monitored = false`
- 3 passos: boas-vindas sem âncora, um com âncora num botão real e um com `helpUrl`

Adicione `data-champs-tour="..."` nos elementos usados no template da página.

## Etapa 6 — Testes de endpoint

Logado como um usuário comum, no navegador:

1. `GET /onboarding/tour?route=<rota>` → retorna o tour com 3 passos e `currentStep: 0`
2. Repetir → retorna o mesmo tour (em andamento), sem criar outra linha
3. `POST /onboarding/progress` `{tour, step: 0, action: "next"}` → `currentStep: 1`
4. `next` no último passo → `status: completed`
5. `GET /onboarding/tour?route=<rota>` → `tour: null` (não dispara de novo)
6. `GET /onboarding/tour/teste-memod` → reabre do início, `views` = 2
7. `skip` → `status: skipped`
8. Ação inválida e slug inexistente → 422 e 404 com mensagem em português
9. Deslogado → redireciona para o login ou retorna 401

Confira cada passo direto na tabela `champs_onboarding_progress`.

## Etapa 7 — Tour monitorado

1. Pelo comando de seed, crie um segundo tour com `monitored = true`, salvo via Doctrine
   para disparar o `TourMonitoringListener`.
2. Com o worker rodando (`messenger:consume <transport> -vv`), confira se a mensagem
   foi processada e se surgiu uma linha `pending` por usuário elegível.
3. Rode `php bin/console champs:onboarding:sync` → deve mostrar 0 adicionados (idempotente).
4. Com `requiredRole` preenchido, confira se só usuários com a role (ou acima, pela hierarquia) entram.
5. Pelas telas do MEMOD:
   - criar usuário → ganha linha `pending`
   - mudar a role para perder acesso → linha `pending` some
   - trocar o e-mail/identifier → linhas acompanham o novo valor
   - excluir usuário → todas as linhas dele somem
6. Confira se salvar usuário continua funcionando mesmo se o monitoramento falhar
   (o erro deve ir só para o log).

## Etapa 8 — Estatísticas

Num comando de dev ou num teste, chame `OnboardingStats::overview()` e
`OnboardingStats::forTour()` e confira os números contra a tabela.

## Etapa 9 — Testes automatizados (no pacote)

Se der tempo, crie no `champs-onboarding` uma suíte PHPUnit mínima:

- Unitários: `TourProgress` (transições de status), `Tour::getEffectiveRoutes()`,
  `ProgressStatus`
- Integração com SQLite: `TourProgressRepository` (métodos DBAL),
  `MonitoringManager::syncTour()` e `syncUser()`

## Entrega

Ao terminar, mostre ao Beto:

1. O que funcionou de primeira
2. O que precisou ser corrigido no bundle (com o diff)
3. O que ficou pendente ou duvidoso
4. Commits feitos no `champs-onboarding` e na branch do MEMOD (sem push sem ele pedir)
