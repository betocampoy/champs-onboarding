<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Repository;

use BetoCampoy\Champs\Onboarding\Entity\Tour;
use BetoCampoy\Champs\Onboarding\Enum\TourTrigger;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tour>
 */
class TourRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tour::class);
    }

    /**
     * Tours ativos que começam na rota informada e podem disparar sozinhos
     * (exclui MANUAL e NEW_FEATURE ainda não publicados), por prioridade.
     *
     * @return list<Tour>
     */
    public function findAutoStartForRoute(string $route, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        return $this->createQueryBuilder('t')
            ->addSelect('s')
            ->leftJoin('t.steps', 's')
            ->andWhere('t.active = true')
            ->andWhere('t.startRoute = :route')
            ->andWhere('t.trigger <> :manual')
            ->andWhere('t.publishedAt IS NULL OR t.publishedAt <= :now')
            ->setParameter('route', $route)
            ->setParameter('manual', TourTrigger::MANUAL)
            ->setParameter('now', $now)
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('t.id', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Busca por slug (botão "?" e reabertura manual). */
    public function findActiveBySlug(string $slug): ?Tour
    {
        return $this->createQueryBuilder('t')
            ->addSelect('s')
            ->leftJoin('t.steps', 's')
            ->andWhere('t.slug = :slug')
            ->andWhere('t.active = true')
            ->setParameter('slug', $slug)
            ->addOrderBy('s.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Tours ativos disponíveis numa rota, inclusive os manuais
     * (para listar no menu de ajuda da página).
     *
     * @return list<Tour>
     */
    public function findAvailableForRoute(string $route): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.active = true')
            ->andWhere('t.startRoute = :route')
            ->setParameter('route', $route)
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tours obrigatórios ativos e já publicados.
     *
     * @return list<Tour>
     */
    public function findMandatoryActive(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        return $this->createQueryBuilder('t')
            ->addSelect('s')
            ->leftJoin('t.steps', 's')
            ->andWhere('t.active = true')
            ->andWhere('t.mandatory = true')
            ->andWhere('t.publishedAt IS NULL OR t.publishedAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('t.id', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tours monitorados e ativos (as linhas PENDING são criadas mesmo antes
     * do publishedAt; o tour só dispara depois da publicação).
     *
     * @return list<Tour>
     */
    public function findMonitoredActive(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.active = true')
            ->andWhere('t.monitored = true')
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Todos os tours com passos (para o dashboard).
     *
     * @return list<Tour>
     */
    public function findAllWithSteps(): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('s')
            ->leftJoin('t.steps', 's')
            ->orderBy('t.active', 'DESC')
            ->addOrderBy('t.name', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Tour $tour, bool $flush = true): void
    {
        $this->getEntityManager()->persist($tour);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Tour $tour, bool $flush = true): void
    {
        $this->getEntityManager()->remove($tour);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
