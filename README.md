# betocampoy/champs-onboarding

Onboarding guiado (tours de interface) para projetos Symfony 7.4, integrado ao
[`champs-frontend`](https://github.com/betocampoy/champs-frontend).

O pacote traz as próprias entidades e tabelas (`champs_onboarding_*`).
O projeto consumidor **não precisa adaptar nenhuma entidade**: o progresso é
gravado pelo `userIdentifier` do usuário logado (`UserInterface::getUserIdentifier()`).

## Estrutura

```
src/
├── ChampsOnboardingBundle.php     # registra config e mapeamento Doctrine
├── Entity/
│   ├── Tour.php                   # champs_onboarding_tour
│   ├── TourStep.php               # champs_onboarding_step
│   └── TourProgress.php           # champs_onboarding_progress
├── Command/SyncMonitoredToursCommand.php  # champs:onboarding:sync
├── Controller/OnboardingController.php   # endpoints JSON
├── EventListener/
│   ├── TourMonitoringListener.php        # tour virou monitorado → agenda sync
│   └── UserLifecycleListener.php         # User criado/alterado/excluído
├── EventSubscriber/MandatoryOnboardingSubscriber.php
├── Exception/OnboardingException.php
├── Manager/
│   ├── OnboardingManager.php      # regras do tour
│   ├── MonitoringManager.php      # linhas "não iniciado"
│   └── OnboardingStats.php        # números do dashboard
├── Message/SyncMonitoredTour.php
├── MessageHandler/SyncMonitoredTourHandler.php
├── Monitoring/MonitoredUserProvider.php
├── Enum/
│   ├── TourTrigger.php            # first_access | new_feature | manual
│   ├── ProgressStatus.php         # pending | in_progress | completed | skipped
│   └── StepPosition.php           # top | bottom | left | right | auto
└── Repository/
    ├── TourRepository.php
    ├── TourStepRepository.php
    └── TourProgressRepository.php
config/
├── routes.php
└── services.php
```

## Instalação durante o desenvolvimento (repositório `path`)

No `composer.json` do projeto (ex.: MEMOD):

```json
"repositories": [
    { "type": "path", "url": "../champs-onboarding", "options": { "symlink": true } }
]
```

```bash
composer require betocampoy/champs-onboarding:@dev
```

Quando estabilizar, troque o `path` por `vcs` apontando para o GitHub e use tags de versão.

## Registro do bundle

`config/bundles.php`:

```php
BetoCampoy\Champs\Onboarding\ChampsOnboardingBundle::class => ['all' => true],
```

`config/packages/champs_onboarding.yaml` (opcional):

```yaml
champs_onboarding:
    admin_role: ROLE_ADMIN
```

## Banco de dados

O mapeamento Doctrine é registrado pelo próprio bundle. Basta gerar e rodar a migration:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## Rotas

`config/routes/champs_onboarding.yaml`:

```yaml
champs_onboarding:
    resource: '@ChampsOnboardingBundle/config/routes.php'
    prefix: /onboarding
```

| Método | Rota | Uso |
|---|---|---|
| GET | `/onboarding/tour?route=app_x` | Tour a exibir nesta rota (retoma ou inicia) |
| GET | `/onboarding/tour/{slug}` | Abre/reabre manualmente (botão "?") |
| POST | `/onboarding/progress` | `{tour, step, action}` com action `next`, `complete` ou `skip` |
| GET | `/onboarding/available?route=app_x` | Tours da página para o menu de ajuda |

No `next`, `step` é o passo em que o usuário estava ao clicar. No último passo, `next` conclui o tour.

## Tour obrigatório

Marque `mandatory = true` no tour. A partir daí:

- `skip` é recusado no backend (HTTP 422) e o front esconde o botão "Pular"
- o `MandatoryOnboardingSubscriber` redireciona qualquer navegação GET para a página
  do tour até ele ser concluído (AJAX, JSON, rotas do onboarding e rotas isentas passam livres)
- a rota inicial do tour obrigatório não pode ter parâmetros obrigatórios

```yaml
champs_onboarding:
    mandatory:
        enabled: true
        exempt_routes: [app_logout, app_termos]
        exempt_route_prefixes: ['_']
        cache_seconds: 300
```

Quando o usuário não tem pendência, isso fica guardado na sessão por `cache_seconds`.
Um tour obrigatório criado depois leva até esse tempo para começar a ser cobrado de quem já está logado.

## Tours monitorados

Marque `monitored = true` no tour. O bundle cria uma linha **não iniciado** (`pending`)
para cada usuário elegível e vai atualizando conforme o usuário abre, conclui ou pula.
Assim o dashboard mostra também quem **nunca abriu** o tour.

```yaml
champs_onboarding:
    monitoring:
        user_class: App\Entity\User
        identifier_property: email   # propriedade por trás do getUserIdentifier()
        roles_property: roles
        batch_size: 500
```

Sem `user_class`, o monitoramento fica desligado e o resto do bundle funciona normalmente.

**Elegível** = tour sem `requiredRole`, ou role alcançável pela hierarquia
(`ROLE_ADMIN` herda os tours de `ROLE_USER`).

**Carga inicial:** ao salvar um tour monitorado (ou mudar `monitored`, `active` ou
`requiredRole`), o `TourMonitoringListener` despacha `SyncMonitoredTour` no Messenger.
Roteie para um transport assíncrono:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            BetoCampoy\Champs\Onboarding\Message\SyncMonitoredTour: async
```

Sem roteamento, a mensagem é processada na hora (síncrona).

**Ciclo de vida do usuário** (listener do Doctrine na `user_class`, sem código no projeto):

| Evento | O que acontece |
|---|---|
| Usuário criado | Cria `pending` nos tours monitorados que ele pode ver |
| Roles alteradas | Cria `pending` nos que passou a ver; remove `pending` dos que deixou de ver |
| Identifier alterado (ex.: e-mail) | Renomeia as linhas: o progresso acompanha o usuário |
| Usuário excluído | Remove todas as linhas dele |

Histórico de quem já abriu o tour nunca é apagado por mudança de role.
Uma falha no monitoramento só é registrada no log: nunca impede salvar o usuário.

**Comando** (para ressincronizar ou depois de importar usuários direto no banco):

```bash
php bin/console champs:onboarding:sync               # todos os monitorados
php bin/console champs:onboarding:sync boas-vindas   # um tour
php bin/console champs:onboarding:sync --async       # só enfileira
```

## Estatísticas

`OnboardingStats` entrega os dados do dashboard:

- `overview()`: por tour — não iniciados, iniciados, em andamento, concluídos, pulados,
  taxa de conclusão (sobre quem abriu), cobertura (sobre todos os elegíveis, só em monitorados),
  tempo médio e reaberturas
- `forTour($tour)`: o resumo acima + funil por passo + atividade recente + lista de quem não abriu

## Âncoras no HTML

```html
<button data-champs-tour="btn-importar">Importar</button>
```

O valor do atributo é o campo `anchor` do `TourStep`.

## Próximas etapas

- CRUD de tours e passos + telas do dashboard (Twig, `@ChampsOnboarding`)
- Módulo `Onboarding.js` no `champs-core-js`
