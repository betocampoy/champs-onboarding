<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Eligibility;

use BetoCampoy\Champs\Onboarding\Entity\Tour;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Padrão: tour sem requiredAttribute é de todos; com atributo, passa pelo
 * isGrantedForUser() com o Tour como subject. Roles respeitam a hierarquia
 * (RoleHierarchyVoter) e qualquer voter do projeto é consultado.
 */
final class AuthorizationEligibilityChecker implements TourEligibilityCheckerInterface
{
    public function __construct(private readonly UserAuthorizationCheckerInterface $auth)
    {
    }

    public function isEligible(UserInterface $user, Tour $tour): bool
    {
        $attribute = $tour->getRequiredAttribute();

        return $attribute === null || $this->auth->isGrantedForUser($user, $attribute, $tour);
    }
}
