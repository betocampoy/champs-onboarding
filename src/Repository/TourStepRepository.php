<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Repository;

use BetoCampoy\Champs\Onboarding\Entity\Tour;
use BetoCampoy\Champs\Onboarding\Entity\TourStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TourStep>
 */
class TourStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TourStep::class);
    }

    /** Próxima posição livre ao adicionar um passo pelo CRUD. */
    public function nextPosition(Tour $tour): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->andWhere('s.tour = :tour')
            ->setParameter('tour', $tour)
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 0 : ((int) $max) + 1;
    }
}
