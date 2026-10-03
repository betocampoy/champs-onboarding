<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Monitoring;

use BetoCampoy\Champs\Onboarding\Eligibility\TourEligibilityCheckerInterface;
use BetoCampoy\Champs\Onboarding\Entity\Tour;
use BetoCampoy\Champs\Onboarding\Segment\UserSegmentResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Acesso aos usuários do projeto sem conhecer a entidade:
 * só a classe (config "monitoring.user_class") e o contrato UserInterface.
 * Elegibilidade e segmento vêm das interfaces que o projeto pode trocar.
 */
final class MonitoredUserProvider
{
    /**
     * @param class-string<UserInterface>|null $userClass
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TourEligibilityCheckerInterface $eligibility,
        private readonly UserSegmentResolverInterface $segments,
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
     * depois de processado. Quem já estava carregado antes (ex.: o usuário
     * logado, quando o Messenger roda síncrono dentro da request) não é
     * desanexado, para não quebrar o resto da request.
     *
     * @return iterable<UserInterface>
     */
    public function iterateUsers(): iterable
    {
        if ($this->userClass === null) {
            throw new \LogicException('Defina champs_onboarding.monitoring.user_class para usar tours monitorados.');
        }

        $alreadyManaged = [];
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[$this->em->getClassMetadata($this->userClass)->getName()] ?? [] as $entity) {
            $alreadyManaged[spl_object_id($entity)] = true;
        }

        $query = $this->em->createQueryBuilder()
            ->select('u')
            ->from($this->userClass, 'u')
            ->getQuery();

        foreach ($query->toIterable() as $user) {
            yield $user;

            if (!isset($alreadyManaged[spl_object_id($user)])) {
                $this->em->detach($user);
            }
        }
    }

    public function isEligible(UserInterface $user, Tour $tour): bool
    {
        return $this->eligibility->isEligible($user, $tour);
    }

    public function segmentOf(UserInterface $user): ?string
    {
        return $this->segments->resolve($user);
    }
}
