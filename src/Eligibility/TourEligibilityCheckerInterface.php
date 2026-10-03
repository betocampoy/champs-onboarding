<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Eligibility;

use BetoCampoy\Champs\Onboarding\Entity\Tour;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Decide se um usuário pode ver um tour. É a única regra de acesso do bundle:
 * vale para o usuário logado (endpoints, tour obrigatório) e para o
 * monitoramento (worker/comando, sem request nem sessão).
 *
 * O padrão (AuthorizationEligibilityChecker) usa isGrantedForUser() com o
 * Tour::requiredAttribute. Para outra regra, o projeto cria a própria
 * implementação (ou um decorator do padrão) e aponta o alias:
 *
 *   # config/services.yaml
 *   BetoCampoy\Champs\Onboarding\Eligibility\TourEligibilityCheckerInterface:
 *       alias: App\Onboarding\MinhaRegraDeElegibilidade
 *
 * Não pode depender da sessão nem do usuário logado: recebe sempre o usuário.
 */
interface TourEligibilityCheckerInterface
{
    public function isEligible(UserInterface $user, Tour $tour): bool;
}
