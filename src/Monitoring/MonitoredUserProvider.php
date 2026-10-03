<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Monitoring;

use BetoCampoy\Champs\Onboarding\Entity\Tour;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Acesso aos usuários do projeto sem conhecer a entidade:
 * só a classe (config "monitoring.user_class") e o contrato UserInterface.
 */
final class MonitoredUserProvider
{
    /**
     * @param class-string<UserInterface>|null $userClass
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly ?string $userClass,
        private readonly int $batchSize,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->userClass !== null;
    }

    public function isUser(object $entity): bool
    {
        return $this->userClass !== null && $entity instanceof $this->userClass;
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Percorre todos os usuários em streaming, liberando cada um da memória
     * depois de processado.
     *
     * @return iterable<UserInterface>
     */
    public function iterateUsers(): iterable
    {
        if ($this->userClass === null) {
            throw new \LogicException('Defina champs_onboarding.monitoring.user_class para usar tours monitorados.');
        }

        $query = $this->em->createQueryBuilder()
            ->select('u')
            ->from($this->userClass, 'u')
            ->getQuery();

        foreach ($query->toIterable() as $user) {
            yield $user;
            $this->em->detach($user);
        }
    }

    /** Elegível = tour sem role exigida ou role alcançável pela hierarquia. */
    public function isEligible(UserInterface $user, Tour $tour): bool
    {
        $required = $tour->getRequiredRole();

        return $required === null
            || in_array($required, $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true);
    }
}
