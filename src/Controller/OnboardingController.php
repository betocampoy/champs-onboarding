<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Controller;

use BetoCampoy\Champs\Onboarding\Exception\OnboardingException;
use BetoCampoy\Champs\Onboarding\Manager\OnboardingManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Endpoints JSON consumidos pelo módulo Onboarding.js.
 * Controller fino: toda regra fica no OnboardingManager.
 *
 * Sem usuário logado responde 401 em JSON (não redireciona para o login):
 * o front só desiste em silêncio. Se o access_control do projeto exigir login
 * em /onboarding, o firewall redireciona antes de chegar aqui.
 *
 * O POST exige o cabeçalho X-Champs-Ajax (o mesmo do AjaxForm do champs-frontend):
 * outro site não consegue enviar cabeçalho customizado sem preflight CORS,
 * e Sec-Fetch-Site "cross-site" é recusado. É a proteção CSRF do endpoint.
 */
final class OnboardingController extends AbstractController
{
    public const AJAX_HEADER = 'X-Champs-Ajax';

    public function __construct(private readonly OnboardingManager $manager)
    {
    }

    /** GET /onboarding/tour?route=app_x → tour a exibir nesta rota (ou null). */
    #[Route('/tour', name: 'champs_onboarding_tour', methods: ['GET'])]
    public function tour(Request $request): JsonResponse
    {
        $route = (string) $request->query->get('route', '');

        return $this->handle(fn (UserInterface $user) => [
            'tour' => $route === '' ? null : $this->manager->resolveForRoute($user, $route),
        ]);
    }

    /** GET /onboarding/tour/{slug} → abre/reabre o tour manualmente. */
    #[Route('/tour/{slug}', name: 'champs_onboarding_tour_start', methods: ['GET'])]
    public function start(string $slug): JsonResponse
    {
        return $this->handle(fn (UserInterface $user) => ['tour' => $this->manager->startManually($user, $slug)]);
    }

    /** POST /onboarding/progress {tour, step, action} */
    #[Route('/progress', name: 'champs_onboarding_progress', methods: ['POST'])]
    public function progress(Request $request): JsonResponse
    {
        return $this->handle(function (UserInterface $user) use ($request) {
            $this->denyCrossSite($request);

            try {
                $data = $request->toArray();
            } catch (JsonException) {
                throw OnboardingException::invalidPayload();
            }

            return $this->manager->recordProgress(
                $user,
                (string) ($data['tour'] ?? ''),
                (string) ($data['action'] ?? ''),
                (int) ($data['step'] ?? -1),
            );
        });
    }

    /** GET /onboarding/available?route=app_x → tours para o menu de ajuda. */
    #[Route('/available', name: 'champs_onboarding_available', methods: ['GET'])]
    public function available(Request $request): JsonResponse
    {
        return $this->handle(fn (UserInterface $user) => [
            'tours' => $this->manager->listAvailable($user, (string) $request->query->get('route', '')),
        ]);
    }

    /** @param callable(UserInterface): array $action */
    private function handle(callable $action): JsonResponse
    {
        try {
            $user = $this->getUser() ?? throw OnboardingException::unauthenticated();

            return $this->json($action($user));
        } catch (OnboardingException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode);
        }
    }

    private function denyCrossSite(Request $request): void
    {
        if (!$request->headers->has(self::AJAX_HEADER) || $request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            throw OnboardingException::crossSiteRequest();
        }
    }
}
