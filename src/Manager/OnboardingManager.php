<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Manager;

use BetoCampoy\Champs\Onboarding\Eligibility\TourEligibilityCheckerInterface;
use BetoCampoy\Champs\Onboarding\Entity\Tour;
use BetoCampoy\Champs\Onboarding\Entity\TourProgress;
use BetoCampoy\Champs\Onboarding\Enum\TourTrigger;
use BetoCampoy\Champs\Onboarding\Exception\OnboardingException;
use BetoCampoy\Champs\Onboarding\Repository\TourProgressRepository;
use BetoCampoy\Champs\Onboarding\Repository\TourRepository;
use BetoCampoy\Champs\Onboarding\Segment\UserSegmentResolverInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Regras do onboarding: qual tour mostrar, registro de progresso
 * e verificação de tours obrigatórios pendentes.
 */
final class OnboardingManager
{
    public const ACTION_NEXT = 'next';
    public const ACTION_COMPLETE = 'complete';
    public const ACTION_SKIP = 'skip';

    public function __construct(
        private readonly TourRepository $tours,
        private readonly TourProgressRepository $progress,
        private readonly TourEligibilityCheckerInterface $eligibility,
        private readonly UserSegmentResolverInterface $segments,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Tour a exibir na rota atual, já no formato do Onboarding.js, ou null.
     *
     * Ordem: 1) retoma um tour em andamento cujo passo atual é nesta rota;
     *        2) inicia um tour automático desta rota ainda não visto.
     */
    public function resolveForRoute(UserInterface $user, string $route): ?array
    {
        $userId = $user->getUserIdentifier();

        foreach ($this->progress->findInProgressForUser($userId) as $progress) {
            $tour = $progress->getTour();
            $routes = $tour->getEffectiveRoutes();

            if (($routes[$progress->getCurrentStep()] ?? null) === $route && $this->canSee($user, $tour)) {
                return $this->toPayload($tour, $progress);
            }
        }

        $candidates = array_values(array_filter(
            $this->tours->findAutoStartForRoute($route),
            fn (Tour $t) => $t->countSteps() > 0 && $this->canSee($user, $t),
        ));
        $seen = $this->progress->findForUserIndexedByTour($userId, $candidates);

        foreach ($candidates as $tour) {
            $progress = $seen[$tour->getId()] ?? null;

            if ($progress !== null && !$progress->isPending()) {
                continue; // já iniciado/concluído/pulado: não dispara de novo sozinho
            }

            // Sem linha (tour não monitorado) ou PENDING (monitorado): começa agora.
            $progress ??= new TourProgress($userId, $tour, false);
            $progress->begin();
            $this->save($user, $progress);

            return $this->toPayload($tour, $progress);
        }

        return null;
    }

    /** Abre (ou reabre do início) um tour pelo slug — botão "?". */
    public function startManually(UserInterface $user, string $slug): array
    {
        $tour = $this->getVisibleTour($user, $slug);
        $userId = $user->getUserIdentifier();

        $progress = $this->progress->findOneForUser($userId, $tour);
        if ($progress === null) {
            $progress = new TourProgress($userId, $tour);
        } elseif ($progress->isPending()) {
            $progress->begin();
        } elseif ($progress->isFinished()) {
            $progress->restart();
        }

        $this->save($user, $progress);

        return $this->toPayload($tour, $progress);
    }

    /**
     * Registra a ação do usuário no tour.
     *
     * @return array{status: string, currentStep: int}
     */
    public function recordProgress(UserInterface $user, string $slug, string $action, int $step): array
    {
        $tour = $this->getVisibleTour($user, $slug);
        $progress = $this->progress->findOneForUser($user->getUserIdentifier(), $tour)
            ?? throw OnboardingException::notStarted($slug);

        if ($step < 0 || $tour->getStepAt($step) === null) {
            throw OnboardingException::invalidStep($step);
        }

        // $step = passo em que o usuário estava quando clicou.
        match ($action) {
            self::ACTION_NEXT => $step >= $tour->getLastPosition()
                ? $progress->complete()
                : $progress->advanceTo($step + 1),
            self::ACTION_COMPLETE => $progress->complete(),
            self::ACTION_SKIP => $tour->isMandatory()
                ? throw OnboardingException::cannotSkipMandatory($slug)
                : $progress->skip($step),
            default => throw OnboardingException::invalidAction($action),
        };

        $this->save($user, $progress);

        return [
            'status' => $progress->getStatus()->value,
            'currentStep' => $progress->getCurrentStep(),
        ];
    }

    /**
     * Tours disponíveis na rota (inclusive manuais), para o menu de ajuda.
     *
     * @return list<array{slug: string, name: string, description: ?string}>
     */
    public function listAvailable(UserInterface $user, string $route): array
    {
        $result = [];
        foreach ($this->tours->findAvailableForRoute($route) as $tour) {
            if ($this->canSee($user, $tour)) {
                $result[] = [
                    'slug' => $tour->getSlug(),
                    'name' => $tour->getName(),
                    'description' => $tour->getDescription(),
                ];
            }
        }

        return $result;
    }

    /**
     * Primeiro tour obrigatório que o usuário ainda não concluiu, ou null.
     * Pular não é permitido em obrigatório, então "pendente" = não concluído.
     */
    public function findPendingMandatory(UserInterface $user): ?Tour
    {
        $mandatory = array_values(array_filter(
            $this->tours->findMandatoryActive(),
            fn (Tour $t) => $t->countSteps() > 0 && $this->canSee($user, $t),
        ));

        $seen = $this->progress->findForUserIndexedByTour($user->getUserIdentifier(), $mandatory);

        foreach ($mandatory as $tour) {
            $progress = $seen[$tour->getId()] ?? null;
            if ($progress === null || !$progress->isFinished()) {
                return $tour;
            }
        }

        return null;
    }

    /**
     * Rota para onde o usuário deve ir para continuar um tour obrigatório:
     * a rota do passo atual (se já começou) ou a rota inicial.
     */
    public function resumeRouteFor(UserInterface $user, Tour $tour): string
    {
        $progress = $this->progress->findOneForUser($user->getUserIdentifier(), $tour);

        if ($progress === null) {
            return $tour->getStartRoute();
        }

        return $tour->getEffectiveRoutes()[$progress->getCurrentStep()] ?? $tour->getStartRoute();
    }

    private function getVisibleTour(UserInterface $user, string $slug): Tour
    {
        $tour = $this->tours->findActiveBySlug($slug) ?? throw OnboardingException::tourNotFound($slug);

        if (!$this->canSee($user, $tour)) {
            throw OnboardingException::accessDenied($slug);
        }

        return $tour;
    }

    /** Mesma regra do monitoramento: TourEligibilityCheckerInterface. */
    private function canSee(UserInterface $user, Tour $tour): bool
    {
        return $this->eligibility->isEligible($user, $tour);
    }

    /** Grava o progresso já com o segmento atual do usuário. */
    private function save(UserInterface $user, TourProgress $progress): void
    {
        $progress->setSegment($this->segments->resolve($user));
        $this->progress->save($progress);
    }

    /**
     * Tour para o "Testar tour" do admin: mesmo formato, do passo 0, sem gravar
     * progresso nem checar elegibilidade/ativo (dá para testar antes de ativar).
     * Quem chama garante que é admin.
     */
    public function previewPayload(Tour $tour): array
    {
        return [...$this->toPayload($tour, null), 'preview' => true];
    }

    /** Formato consumido pelo módulo Onboarding.js. */
    private function toPayload(Tour $tour, ?TourProgress $progress): array
    {
        $routes = $tour->getEffectiveRoutes();
        $steps = [];

        $ordered = $tour->getSteps()->toArray();
        usort($ordered, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());

        foreach ($ordered as $step) {
            $route = $routes[$step->getPosition()];
            $steps[] = [
                ...$step->toArray(),
                'route' => $route,
                'url' => $this->tryGenerate($route),
            ];
        }

        return [
            'tour' => $tour->getSlug(),
            'name' => $tour->getName(),
            'mandatory' => $tour->isMandatory(),
            'manual' => $tour->getTrigger() === TourTrigger::MANUAL,
            'currentStep' => $progress?->getCurrentStep() ?? 0,
            'steps' => $steps,
        ];
    }

    /** Rotas com parâmetros obrigatórios não geram URL; o front então não navega sozinho. */
    private function tryGenerate(string $route): ?string
    {
        try {
            return $this->urls->generate($route);
        } catch (RoutingException) {
            return null;
        }
    }
}
