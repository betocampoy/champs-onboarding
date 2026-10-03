<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Controller;

use BetoCampoy\Champs\Onboarding\Exception\OnboardingException;
use BetoCampoy\Champs\Onboarding\Manager\OnboardingManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoints JSON consumidos pelo módulo Onboarding.js.
 * Controller fino: toda regra fica no OnboardingManager.
 */
#[IsGranted('IS_AUTHENTICATED')]
final class OnboardingController extends AbstractController
{
    public function __construct(private readonly OnboardingManager $manager)
    {
    }

    /** GET /onboarding/tour?route=app_x → tour a exibir nesta rota (ou null). */
    #[Route('/tour', name: 'champs_onboarding_tour', methods: ['GET'])]
    public function tour(Request $request): JsonResponse
    {
        $route = (string) $request->query->get('route', '');

        if ($route === '') {
            return $this->json(['tour' => null]);
        }

        return $this->json(['tour' => $this->manager->resolveForRoute($this->getUser(), $route)]);
    }

    /** GET /onboarding/tour/{slug} → abre/reabre o tour manualmente. */
    #[Route('/tour/{slug}', name: 'champs_onboarding_tour_start', methods: ['GET'])]
    public function start(string $slug): JsonResponse
    {
        return $this->handle(fn () => ['tour' => $this->manager->startManually($this->getUser(), $slug)]);
    }

    /** POST /onboarding/progress {tour, step, action} */
    #[Route('/progress', name: 'champs_onboarding_progress', methods: ['POST'])]
    public function progress(Request $request): JsonResponse
    {
        $data = $request->toArray();

        return $this->handle(fn () => $this->manager->recordProgress(
            $this->getUser(),
            (string) ($data['tour'] ?? ''),
            (string) ($data['action'] ?? ''),
            (int) ($data['step'] ?? -1),
        ));
    }

    /** GET /onboarding/available?route=app_x → tours para o menu de ajuda. */
    #[Route('/available', name: 'champs_onboarding_available', methods: ['GET'])]
    public function available(Request $request): JsonResponse
    {
        return $this->json(['tours' => $this->manager->listAvailable((string) $request->query->get('route', ''))]);
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $this->json($action());
        } catch (OnboardingException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode);
        }
    }
}
