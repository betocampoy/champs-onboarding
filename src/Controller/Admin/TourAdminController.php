<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Controller\Admin;

use BetoCampoy\Champs\Onboarding\Admin\AnchorCatalog;
use BetoCampoy\Champs\Onboarding\Admin\EligibleUserFinder;
use BetoCampoy\Champs\Onboarding\Admin\RouteCatalog;
use BetoCampoy\Champs\Onboarding\Segment\UserSegmentResolverInterface;
use BetoCampoy\Champs\Onboarding\Entity\Tour;
use BetoCampoy\Champs\Onboarding\Entity\TourStep;
use BetoCampoy\Champs\Onboarding\Enum\TourTrigger;
use BetoCampoy\Champs\Onboarding\Form\TourFormType;
use BetoCampoy\Champs\Onboarding\Form\TourStepFormType;
use BetoCampoy\Champs\Onboarding\Manager\OnboardingManager;
use BetoCampoy\Champs\Onboarding\Manager\OnboardingStats;
use BetoCampoy\Champs\Onboarding\Repository\TourRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cadastro de tours e passos (só para champs_onboarding.admin_role).
 * Telas de página inteira com redirect + flash, para funcionar em qualquer
 * projeto; layout e tema dos formulários vêm da config champs_onboarding.admin.
 */
#[Route('/admin/tours')]
final class TourAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TourRepository $tours,
        private readonly OnboardingStats $stats,
        private readonly OnboardingManager $manager,
        private readonly AnchorCatalog $anchors,
        private readonly TranslatorInterface $translator,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UserAuthorizationCheckerInterface $userAuth,
        private readonly RouteCatalog $routeCatalog,
        private readonly EligibleUserFinder $eligibleUsers,
        private readonly UserSegmentResolverInterface $segments,
        #[Autowire(param: 'champs_onboarding.admin_role')] private readonly string $adminRole,
        #[Autowire(param: 'champs_onboarding.admin.layout')] private readonly string $layout,
        #[Autowire(param: 'champs_onboarding.admin.form_theme')] private readonly string $formTheme,
        #[Autowire(param: 'champs_onboarding.admin.switch_user_parameter')] private readonly ?string $switchUserParameter,
    ) {
    }

    /**
     * Sugestões do "Testar como…" / "Apontar como…": usuários que podem ver o tour.
     * GET ?q=trecho do identificador → [{identifier, label}]
     */
    #[Route('/{tour}/usuarios', name: 'champs_onboarding_admin_tour_users', requirements: ['tour' => '\d+'], methods: ['GET'])]
    public function users(Request $request, #[MapEntity(id: 'tour')] Tour $tour): JsonResponse
    {
        $this->denyAccessUnlessGranted($this->adminRole);

        $me = $this->getUser()?->getUserIdentifier();
        $found = array_filter(
            $this->eligibleUsers->find($tour, (string) $request->query->get('q', ''), 21),
            static fn (array $u) => $u['identifier'] !== $me, // personificar a si mesmo não faz sentido
        );

        $users = array_map(function (array $u): array {
            $label = $u['segment'] !== null ? $this->segments->label($u['segment']) : null;

            return ['identifier' => $u['identifier'], 'label' => $label ? $u['identifier'] . ' — ' . $label : $u['identifier']];
        }, array_slice(array_values($found), 0, 20));

        return $this->json(['users' => $users]);
    }

    #[Route('', name: 'champs_onboarding_admin_tour_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->adminRole);

        $q = mb_strtolower(trim((string) $request->query->get('q', '')));
        $rows = array_values(array_filter(
            $this->stats->overview(),
            static fn (array $row) => $q === ''
                || str_contains(mb_strtolower($row['tour']->getName()), $q)
                || str_contains($row['tour']->getSlug(), $q)
                || str_contains($row['tour']->getStartRoute(), $q),
        ));

        return $this->renderAdmin('@ChampsOnboarding/admin/tour/index.html.twig', ['rows' => $rows, 'q' => $q]);
    }

    #[Route('/novo', name: 'champs_onboarding_admin_tour_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->adminRole);

        $tour = (new Tour('', '', ''))->setTrigger(TourTrigger::FIRST_ACCESS);
        $form = $this->createForm(TourFormType::class, $tour);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($tour);
            $this->em->flush();
            $this->flash('success', 'admin.flash.tour_created');

            return $this->redirectToRoute('champs_onboarding_admin_tour_edit', ['tour' => $tour->getId()]);
        }

        return $this->renderAdmin('@ChampsOnboarding/admin/tour/form.html.twig', ['form' => $form, 'tour' => null]);
    }

    #[Route('/{tour}', name: 'champs_onboarding_admin_tour_edit', requirements: ['tour' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, #[MapEntity(id: 'tour')] Tour $tour): Response
    {
        $this->denyAccessUnlessGranted($this->adminRole);

        $form = $this->createForm(TourFormType::class, $tour);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->flash('success', 'admin.flash.tour_saved');

            return $this->redirectToRoute('champs_onboarding_admin_tour_edit', ['tour' => $tour->getId()]);
        }

        return $this->renderAdmin('@ChampsOnboarding/admin/tour/form.html.twig', [
            'form' => $form,
            'tour' => $tour,
            'steps' => $this->orderedSteps($tour),
            'routes' => $tour->getEffectiveRoutes(),
            'missingAnchors' => $this->missingAnchors($tour),
            'previewUrl' => $this->previewUrl($tour),
        ]);
    }

    #[Route('/{tour}/alternar', name: 'champs_onboarding_admin_tour_toggle', requirements: ['tour' => '\d+'], methods: ['POST'])]
    public function toggle(Request $request, #[MapEntity(id: 'tour')] Tour $tour): Response
    {
        $this->denyAccessUnlessGranted($this->adminRole);

        if ($this->validToken('toggle_tour_' . $tour->getId(), $request)) {
            $tour->setActive(!$tour->isActive());
            $this->em->flush();
            $this->flash('success', $tour->isActive() ? 'admin.flash.tour_activated' : 'admin.flash.tour_deactivated');
        }

        return $this->redirectBack($request, 'champs_onboarding_admin_tour_index');
    }

    #[Route('/{tour}/excluir', name: 'champs_onboarding_admin_tour_delete', requirements: ['tour' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, #[MapEntity(id: 'tour')] Tour $tour): Response
    {
        $this->denyAccessUnlessGranted($this->adminRole);

        if ($this->validToken('delete_tour_' . $tour->getId(), $request)) {
            $this->em->remove($tour); // passos e progresso caem em cascata
            $this->em->flush();
            $this->flash('success', 'admin.flash.tour_deleted');
        }

        return $this->redirectToRoute('champs_onboarding_admin_tour_index');
    }

    /** Abre a tela inicial já rodando o tour, sem gravar progresso (Onboarding.js em modo preview). */
    #[Route('/{tour}/testar', name: 'champs_onboarding_admin_tour_preview', requirements: ['tour' => '\d+'], methods: ['GET'])]
    public function preview(#[MapEntity(id: 'tour')] Tour $tour): Response
    {
        $this->denyAccessUnlessGranted($this->adminRole);

        if ($tour->countSteps() === 0) {
            $this->flash('warning', 'admin.flash.preview_no_steps');

            return $this->redirectToRoute('champs_onboarding_admin_tour_edit', ['tour' => $tour->getId()]);
        }

        $url = $this->previewUrl($tour);
        if ($url === null) {
            $this->flash('error', 'admin.flash.preview_bad_route');

            return $this->redirectToRoute('champs_onboarding_admin_tour_edit', ['tour' => $tour->getId()]);
        }

        return $this->redirect($url);
    }

    /** URL da tela do 1º passo com ?champs_onboarding_preview=slug (null se a rota exigir parâmetros). */
    private function previewUrl(Tour $tour): ?string
    {
        if ($tour->countSteps() === 0 || $tour->getStartRoute() === '') {
            return null;
        }

        try {
            return $this->generateUrl(
                $tour->getEffectiveRoutes()[0] ?? $tour->getStartRoute(),
                ['champs_onboarding_preview' => $tour->getSlug()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        } catch (RoutingException) {
            return null;
        }
    }

    /**
     * Payload do preview, consumido pelo Onboarding.js (?champs_onboarding_preview=slug).
     * Vale para o admin e também para quem está personificando um usuário ("entrar como")
     * sendo admin: é o jeito de testar tours de telas que o admin não acessa.
     */
    #[Route('/preview/{slug}', name: 'champs_onboarding_admin_tour_preview_data', methods: ['GET'])]
    public function previewData(string $slug): JsonResponse
    {
        if (!$this->canPreview()) {
            return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $tour = $this->tours->findOneBy(['slug' => $slug]);
        if ($tour === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['tour' => $this->manager->previewPayload($tour)]);
    }

    // ------------------------------------------------------------ passos

    #[Route('/{tour}/passos/novo', name: 'champs_onboarding_admin_step_new', requirements: ['tour' => '\d+'], methods: ['GET', 'POST'])]
    public function stepNew(Request $request, #[MapEntity(id: 'tour')] Tour $tour): Response
    {
        $this->denyAccessUnlessGranted($this->adminRole);

        $step = new TourStep('', '');
        $form = $this->createForm(TourStepFormType::class, $step);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $tour->addStep($step);
            $this->em->persist($step);
            $this->em->flush();
            $this->flash('success', 'admin.flash.step_created');

            return $this->redirectToRoute('champs_onboarding_admin_tour_edit', ['tour' => $tour->getId()]);
        }

        return $this->renderAdmin('@ChampsOnboarding/admin/step/form.html.twig', [
            'form' => $form,
            'tour' => $tour,
            'step' => null,
            'anchors' => $this->anchors->all(),
            ...$this->pickContext($tour, null),
        ]);
    }

    #[Route('/passos/{step}', name: 'champs_onboarding_admin_step_edit', requirements: ['step' => '\d+'], methods: ['GET', 'POST'])]
    public function stepEdit(Request $request, #[MapEntity(id: 'step')] TourStep $step): Response
    {
        $this->denyAccessUnlessGranted($this->adminRole);

        $form = $this->createForm(TourStepFormType::class, $step);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->flash('success', 'admin.flash.step_saved');

            return $this->redirectToRoute('champs_onboarding_admin_tour_edit', ['tour' => $step->getTour()->getId()]);
        }

        return $this->renderAdmin('@ChampsOnboarding/admin/step/form.html.twig', [
            'form' => $form,
            'tour' => $step->getTour(),
            'step' => $step,
            'anchors' => $this->anchors->all(),
            ...$this->pickContext($step->getTour(), $step),
        ]);
    }

    #[Route('/passos/{step}/mover/{direction}', name: 'champs_onboarding_admin_step_move', requirements: ['step' => '\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function stepMove(Request $request, #[MapEntity(id: 'step')] TourStep $step, string $direction): Response
    {
        $this->denyAccessUnlessGranted($this->adminRole);

        $tour = $step->getTour();
        if ($this->validToken('step_' . $step->getId(), $request)) {
            $tour->moveStep($step, $direction === 'up' ? -1 : 1);
            $this->em->flush();
        }

        return $this->redirectToRoute('champs_onboarding_admin_tour_edit', ['tour' => $tour->getId()]);
    }

    #[Route('/passos/{step}/excluir', name: 'champs_onboarding_admin_step_delete', requirements: ['step' => '\d+'], methods: ['POST'])]
    public function stepDelete(Request $request, #[MapEntity(id: 'step')] TourStep $step): Response
    {
        $this->denyAccessUnlessGranted($this->adminRole);

        $tour = $step->getTour();
        if ($this->validToken('step_' . $step->getId(), $request)) {
            $tour->removeStep($step); // orphanRemoval apaga; renumera os demais
            $this->em->flush();
            $this->flash('success', 'admin.flash.step_deleted');
        }

        return $this->redirectToRoute('champs_onboarding_admin_tour_edit', ['tour' => $tour->getId()]);
    }

    // ------------------------------------------------------------ apoio

    private function canPreview(): bool
    {
        if ($this->isGranted($this->adminRole)) {
            return true;
        }

        $token = $this->tokenStorage->getToken();
        $original = $token instanceof SwitchUserToken ? $token->getOriginalToken()->getUser() : null;

        return $original !== null && $this->userAuth->isGrantedForUser($original, $this->adminRole);
    }

    private function renderAdmin(string $template, array $params): Response
    {
        $tour = $params['tour'] ?? null;
        $switch = $this->switchUserParameter !== null && $this->eligibleUsers->isAvailable() ? $this->switchUserParameter : null;

        return $this->render($template, [
            ...$params,
            'champs_onboarding_layout' => $this->layout,
            'champs_onboarding_form_theme' => $this->formTheme,
            // "Testar como…" / "Apontar como…" (null = desligado)
            'switchUserParameter' => $switch,
            'usersUrl' => $tour?->getId() && $switch ? $this->generateUrl('champs_onboarding_admin_tour_users', ['tour' => $tour->getId()]) : null,
        ]);
    }

    /** Dados do "Apontar na tela": URL de cada tela e a tela do passo (a própria ou a herdada). */
    private function pickContext(Tour $tour, ?TourStep $step): array
    {
        $routes = $tour->getEffectiveRoutes();
        $inherited = $step !== null
            ? ($routes[$step->getPosition()] ?? $tour->getStartRoute())
            : ($routes === [] ? $tour->getStartRoute() : end($routes));

        return ['routeUrls' => $this->routeCatalog->urls(), 'inheritedRoute' => $inherited];
    }

    /** @return list<TourStep> */
    private function orderedSteps(Tour $tour): array
    {
        $steps = $tour->getSteps()->toArray();
        usort($steps, static fn (TourStep $a, TourStep $b) => $a->getPosition() <=> $b->getPosition());

        return $steps;
    }

    /** Âncoras dos passos que não aparecem em nenhum template conhecido (provável erro de digitação). */
    private function missingAnchors(Tour $tour): array
    {
        $known = $this->anchors->all();
        $missing = [];
        foreach ($tour->getSteps() as $step) {
            // seletor CSS não dá para conferir pelo template: só na tela
            if ($step->getAnchor() !== null && !TourStep::isSelectorAnchor($step->getAnchor()) && !isset($known[$step->getAnchor()])) {
                $missing[$step->getId()] = true;
            }
        }

        return $missing;
    }

    private function validToken(string $id, Request $request): bool
    {
        if ($this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            return true;
        }
        $this->flash('error', 'admin.flash.invalid_token');

        return false;
    }

    private function flash(string $type, string $key): void
    {
        $this->addFlash($type, $this->translator->trans($key, [], 'champs_onboarding'));
    }

    private function redirectBack(Request $request, string $fallbackRoute): Response
    {
        $referer = (string) $request->headers->get('referer', '');

        return $referer !== '' && str_starts_with($referer, $request->getSchemeAndHttpHost())
            ? $this->redirect($referer)
            : $this->redirectToRoute($fallbackRoute);
    }
}
