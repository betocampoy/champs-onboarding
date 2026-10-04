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

Exige `betocampoy/champs-frontend` `^1.7`.

## Banco de dados

O mapeamento Doctrine é registrado pelo próprio bundle. Basta gerar e rodar a migration:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

**Leia a migration gerada.** Ela deve criar só `champs_onboarding_tour`, `champs_onboarding_step`
e `champs_onboarding_progress`. Se o banco do projeto já tiver drift em relação ao mapeamento,
o `diff` arrasta junto `ALTER`/`DROP` de tabelas do projeto: nesse caso, limpe à mão.

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
Erros de regra voltam como JSON `{error}` em português (401 sem login, 404 tour inexistente,
403 sem acesso, 422 ação/passo/corpo inválido, 409 tour não iniciado).

- **Sem login** os endpoints respondem 401 em JSON (não redirecionam). Se o `access_control` do
  projeto exigir login em `/onboarding`, o firewall redireciona antes.
- **CSRF:** o `POST /progress` exige o cabeçalho `X-Champs-Ajax` (o mesmo do AjaxForm do
  champs-frontend) e recusa `Sec-Fetch-Site: cross-site` (403). Outro site não consegue enviar
  cabeçalho customizado sem preflight CORS.

As rotas só existem pelo import acima. O `config/routes.yaml` padrão do Symfony 7.4
(`resource: routing.controllers`) importaria todo controller com `#[Route]`, inclusive os do
bundle e sem prefixo; o `ExcludeFromRoutingControllersPass` tira os controllers do bundle dessa descoberta.

## Quem vê cada tour (elegibilidade)

`Tour::requiredAttribute` é um atributo de segurança (null = qualquer usuário logado). A regra
fica num único serviço, `TourEligibilityCheckerInterface`, usado tanto nos endpoints quanto
no monitoramento (worker/comando, sem sessão).

O padrão (`AuthorizationEligibilityChecker`) chama `isGrantedForUser($user, $atributo, $tour)`:

- `ROLE_X` funciona sem configuração e respeita a `role_hierarchy`
- qualquer outro atributo é respondido pelos voters do projeto (o `Tour` vai como subject).
  Ex.: `perm:fatura.listar`, `modulo:financeiro`. Os voters não podem depender da sessão.

Para outra regra (ex.: excluir usuários desativados), implemente a interface no projeto
(pode receber o `AuthorizationEligibilityChecker` e complementar) e aponte o alias:

```php
#[AsAlias(TourEligibilityCheckerInterface::class)]
final class MinhaRegra implements TourEligibilityCheckerInterface { /* ... */ }
```

## Segmento (estatísticas por tenant, unidade, plano...)

Os tours são globais. Para filtrar e agrupar as estatísticas, implemente
`UserSegmentResolverInterface` (`resolve($user): ?string` e `label($segment): string`) e aponte
o alias do mesmo jeito. O segmento é gravado em `champs_onboarding_progress.segment`:
quando o usuário usa o tour, quando muda um dos `watch_fields` e em cada `champs:onboarding:sync`
(que também corrige segmentos desatualizados). Sem implementação, fica tudo sem segmento.

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
        watch_fields: [roles]        # o que muda a elegibilidade ou o segmento
        batch_size: 500
```

Sem `user_class`, o monitoramento fica desligado e o resto do bundle funciona normalmente.

**Elegível** = o `TourEligibilityCheckerInterface` diz que sim (ver acima).

**`watch_fields`**: campos, associações to-one ou coleções (ManyToMany/OneToMany) do User.
Mudança em qualquer um ressincroniza o usuário. Inclua tudo de que a elegibilidade e o segmento
dependem (ex.: `[tenant, authRoles, status]`).

**Carga inicial:** ao salvar um tour monitorado (ou mudar `monitored`, `active` ou
`requiredAttribute`), o `TourMonitoringListener` despacha `SyncMonitoredTour` no Messenger.

**Mudanças fora do User** (ex.: o tenant contratou um módulo): despache
`SyncMonitoredUsers($segmento)` para ressincronizar os usuários daquele segmento
(ou `null` para todos).

Roteie as duas mensagens para um transport assíncrono:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            BetoCampoy\Champs\Onboarding\Message\SyncMonitoredTour: async
            BetoCampoy\Champs\Onboarding\Message\SyncMonitoredUsers: async
```

Sem roteamento, a mensagem é processada na hora (síncrona).

**Ciclo de vida do usuário** (listener do Doctrine na `user_class`, sem código no projeto):

| Evento | O que acontece |
|---|---|
| Usuário criado | Cria `pending` nos tours monitorados que ele pode ver |
| Algum `watch_fields` alterado | Cria `pending` nos que passou a ver, remove `pending` dos que deixou de ver e atualiza o segmento |
| Identifier alterado (ex.: e-mail) | Renomeia as linhas: o progresso acompanha o usuário |
| Usuário excluído | Remove todas as linhas dele |

Histórico de quem já abriu o tour nunca é apagado por mudança de acesso.
Uma falha no monitoramento só é registrada no log: nunca impede salvar o usuário.

**Comando** (para ressincronizar ou depois de importar usuários direto no banco):

```bash
php bin/console champs:onboarding:sync               # todos os monitorados
php bin/console champs:onboarding:sync boas-vindas   # um tour
php bin/console champs:onboarding:sync --async       # só enfileira
```

## Estatísticas

`OnboardingStats` entrega os dados do dashboard:

- `overview(?segment)`: por tour — não iniciados, iniciados, em andamento, concluídos, pulados,
  taxa de conclusão (sobre quem abriu), cobertura (sobre todos os elegíveis, só em monitorados),
  tempo médio e reaberturas
- `forTour($tour, ?segment)`: o resumo acima + funil por passo + atividade recente + lista de quem não abriu
- `bySegment($tour)`: o resumo do tour por segmento (com o `label`), do maior para o menor

`segment = null` nos dois primeiros = todos os segmentos.

## Front (champs-frontend ≥ 1.8)

O módulo `Onboarding.js` do `champs-core-js` já vem no `initCore()`. No layout das páginas logadas:

```twig
{# raiz: uma por página #}
<div class="d-none" data-champs-onboarding
     data-champs-onboarding-route="{{ app.request.attributes.get('_route') }}"
     data-champs-onboarding-url="{{ path('champs_onboarding_tour')|slice(0, -5) }}"></div>

{# botão de ajuda: reabre / lista os tours da página #}
<button type="button" class="btn btn-link" data-champs-onboarding-help><i class="bi bi-question-circle"></i></button>
```

Âncoras dos passos (o valor é o campo `anchor` do `TourStep`):

```html
<button data-champs-tour="btn-importar">Importar</button>
```

Textos traduzidos, atributos e eventos: ver o README do `champs-core-js` (seção Onboarding).

## Cadastro de tours (admin)

Telas em `/onboarding/admin/tours` (prefixo do import de rotas), só para `admin_role`:
lista com busca e números de uso, cadastro do tour, passos (criar, editar, subir/descer, excluir)
e **Testar tour** (modo teste do `Onboarding.js`: nada é gravado, funciona com tour inativo).

```yaml
champs_onboarding:
    admin_role: ROLE_ADMIN
    admin:
        layout: admin/layout.html.twig        # precisa ter o bloco "content"
        form_theme: '@ChampsFrontend/form/champs_theme.html.twig'
        route_path_prefixes: [/app]           # telas oferecidas no select (vazio = todas)
        anchor_paths: ['%kernel.project_dir%/templates']  # onde procurar data-champs-tour
```

- Só rotas GET sem parâmetro obrigatório aparecem como tela de tour (o front precisa gerar a URL).
- As âncoras `data-champs-tour` já usadas nos templates são sugeridas no cadastro do passo;
  âncora digitada que não existe em nenhum template aparece com aviso.
- Telas e textos (`champs_onboarding.*.yaml`, pt_BR/en/es) podem ser sobrescritos pelo mecanismo
  padrão do Symfony (`templates/bundles/ChampsOnboardingBundle/`, `translations/`).
- **Testar tour** abre `?champs_onboarding_preview=<slug>` na tela do 1º passo. O tour é lido por
  `/onboarding/admin/tours/preview/{slug}`, liberado para o admin e para um admin que esteja
  personificando um usuário (`switch_user`): é o caminho para testar tours de telas que o admin
  não acessa. A tela do tour mostra o link de teste para copiar.

## Próximas etapas

- Dashboard de estatísticas (`OnboardingStats`) nas telas do admin
