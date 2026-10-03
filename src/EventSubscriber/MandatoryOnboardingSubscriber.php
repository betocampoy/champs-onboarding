<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\EventSubscriber;

use BetoCampoy\Champs\Onboarding\Manager\OnboardingManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Enquanto houver tour obrigatório pendente, redireciona a navegação
 * para a página do tour. Requisições AJAX/JSON, rotas do onboarding,
 * rotas isentas e as próprias rotas do tour passam livres.
 *
 * Quando não há pendência, o resultado fica em cache na sessão por
 * alguns minutos para não consultar o banco a cada página.
 */
final class MandatoryOnboardingSubscriber implements EventSubscriberInterface
{
    private const SESSION_KEY = 'champs_onboarding.mandatory_checked_at';

    /**
     * @param list<string> $exemptRoutes        rotas exatas liberadas (ex.: app_logout)
     * @param list<string> $exemptRoutePrefixes prefixos liberados (ex.: "_" cobre _wdt, _profiler, _error)
     */
    public function __construct(
        private readonly OnboardingManager $manager,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
        private readonly array $exemptRoutes,
        private readonly array $exemptRoutePrefixes,
        private readonly int $cacheSeconds,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Prioridade abaixo do firewall (8) para o usuário já estar autenticado.
        return [KernelEvents::REQUEST => ['onRequest', 0]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');

        if ($this->isExempt($request, $route)) {
            return;
        }

        $user = $this->security->getUser();
        if ($user === null || $this->recentlyCleared($request)) {
            return;
        }

        $tour = $this->manager->findPendingMandatory($user);

        if ($tour === null) {
            $this->markCleared($request);
            return;
        }

        if (in_array($route, $tour->getAllRoutes(), true)) {
            return; // já está numa página do tour
        }

        $target = $this->manager->resumeRouteFor($user, $tour);

        try {
            $event->setResponse(new RedirectResponse($this->urls->generate($target)));
        } catch (RoutingException $e) {
            // Rota com parâmetros obrigatórios não serve como destino de tour obrigatório.
            $this->logger->warning('champs_onboarding: não foi possível redirecionar para o tour obrigatório', [
                'tour' => $tour->getSlug(),
                'route' => $target,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isExempt(Request $request, string $route): bool
    {
        if ($route === '' || $request->isXmlHttpRequest() || !$request->isMethod('GET')) {
            return true;
        }

        if (str_starts_with($route, 'champs_onboarding_') || in_array($route, $this->exemptRoutes, true)) {
            return true;
        }

        foreach ($this->exemptRoutePrefixes as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return in_array('application/json', $request->getAcceptableContentTypes(), true);
    }

    private function recentlyCleared(Request $request): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        $checkedAt = $request->getSession()->get(self::SESSION_KEY);

        return is_int($checkedAt) && (time() - $checkedAt) < $this->cacheSeconds;
    }

    private function markCleared(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->set(self::SESSION_KEY, time());
        }
    }
}
