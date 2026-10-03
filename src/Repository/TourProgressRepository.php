<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Repository;

use BetoCampoy\Champs\Onboarding\Entity\Tour;
use BetoCampoy\Champs\Onboarding\Entity\TourProgress;
use BetoCampoy\Champs\Onboarding\Enum\ProgressStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TourProgress>
 *
 * Os métodos "bulk" (DBAL) são usados pelo monitoramento: rodam dentro de
 * listeners do Doctrine e em cargas grandes, onde passar pelo UnitOfWork
 * seria lento ou provocaria flush aninhado.
 */
class TourProgressRepository extends ServiceEntityRepository
{
    private const CHUNK = 500;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TourProgress::class);
    }

    // ---------------------------------------------------------------- ORM

    public function findOneForUser(string $userIdentifier, Tour $tour): ?TourProgress
    {
        return $this->findOneBy(['userIdentifier' => $userIdentifier, 'tour' => $tour]);
    }

    /**
     * Progresso do usuário nos tours informados, indexado pelo id do tour.
     *
     * @param list<Tour> $tours
     * @return array<int, TourProgress>
     */
    public function findForUserIndexedByTour(string $userIdentifier, array $tours): array
    {
        if ($tours === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->andWhere('p.userIdentifier = :user')
            ->andWhere('p.tour IN (:tours)')
            ->setParameter('user', $userIdentifier)
            ->setParameter('tours', $tours)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($rows as $progress) {
            $indexed[$progress->getTour()->getId()] = $progress;
        }

        return $indexed;
    }

    /**
     * Tours em andamento do usuário (para retomar tours que atravessam páginas).
     *
     * @return list<TourProgress>
     */
    public function findInProgressForUser(string $userIdentifier): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('t', 's')
            ->join('p.tour', 't')
            ->leftJoin('t.steps', 's')
            ->andWhere('p.userIdentifier = :user')
            ->andWhere('p.status = :status')
            ->andWhere('t.active = true')
            ->setParameter('user', $userIdentifier)
            ->setParameter('status', ProgressStatus::IN_PROGRESS)
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Linhas brutas de progresso de um tour, usadas pelas estatísticas.
     *
     * @return list<array{status: ProgressStatus, currentStep: int, views: int, startedAt: ?\DateTimeImmutable, finishedAt: ?\DateTimeImmutable}>
     */
    public function findStatsRows(Tour $tour): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.status', 'p.currentStep', 'p.views', 'p.startedAt', 'p.finishedAt')
            ->andWhere('p.tour = :tour')
            ->setParameter('tour', $tour)
            ->getQuery()
            ->getArrayResult();
    }

    /** Últimas atividades no tour (ignora quem ainda não abriu). @return list<TourProgress> */
    public function findRecent(Tour $tour, int $limit = 20): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tour = :tour')
            ->andWhere('p.status <> :pending')
            ->setParameter('tour', $tour)
            ->setParameter('pending', ProgressStatus::PENDING)
            ->orderBy('p.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Quem ainda não abriu um tour monitorado (mais antigos primeiro). @return list<TourProgress> */
    public function findNotStarted(Tour $tour, int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tour = :tour')
            ->andWhere('p.status = :pending')
            ->setParameter('tour', $tour)
            ->setParameter('pending', ProgressStatus::PENDING)
            ->orderBy('p.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function save(TourProgress $progress, bool $flush = true): void
    {
        $this->getEntityManager()->persist($progress);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    // ---------------------------------------------------------------- DBAL (monitoramento)

    /** @return array<string, string> [userIdentifier => status] */
    public function fetchStatusesForTour(int $tourId): array
    {
        $sql = sprintf(
            'SELECT %s, %s FROM %s WHERE %s = ?',
            $this->col('userIdentifier'), $this->col('status'), $this->table(), $this->tourCol(),
        );

        return $this->conn()->fetchAllKeyValue($sql, [$tourId]);
    }

    /** @return array<int, string> [tourId => status] */
    public function fetchStatusesForUser(string $userIdentifier): array
    {
        $sql = sprintf(
            'SELECT %s, %s FROM %s WHERE %s = ?',
            $this->tourCol(), $this->col('status'), $this->table(), $this->col('userIdentifier'),
        );

        return $this->conn()->fetchAllKeyValue($sql, [$userIdentifier]);
    }

    /**
     * Cria linhas PENDING. Quem já tem linha deve ser filtrado antes.
     *
     * @param list<string> $userIdentifiers
     */
    public function insertPending(int $tourId, array $userIdentifiers): int
    {
        if ($userIdentifiers === []) {
            return 0;
        }

        $conn = $this->conn();
        $now = new \DateTimeImmutable();

        $conn->transactional(function (Connection $conn) use ($tourId, $userIdentifiers, $now): void {
            foreach ($userIdentifiers as $identifier) {
                $conn->insert($this->table(), [
                    $this->col('userIdentifier') => $identifier,
                    $this->tourCol() => $tourId,
                    $this->col('currentStep') => 0,
                    $this->col('status') => ProgressStatus::PENDING->value,
                    $this->col('views') => 0,
                    $this->col('createdAt') => $now,
                ], [
                    $this->col('createdAt') => Types::DATETIME_IMMUTABLE,
                ]);
            }
        });

        return count($userIdentifiers);
    }

    /**
     * Remove linhas PENDING de um tour para os usuários informados.
     *
     * @param list<string> $userIdentifiers
     */
    public function deletePendingForTourUsers(int $tourId, array $userIdentifiers): int
    {
        $removed = 0;

        foreach (array_chunk($userIdentifiers, self::CHUNK) as $chunk) {
            $removed += $this->conn()->executeStatement(
                sprintf(
                    'DELETE FROM %s WHERE %s = ? AND %s = ? AND %s IN (?)',
                    $this->table(), $this->tourCol(), $this->col('status'), $this->col('userIdentifier'),
                ),
                [$tourId, ProgressStatus::PENDING->value, $chunk],
                [null, null, ArrayParameterType::STRING],
            );
        }

        return $removed;
    }

    /** @param list<int> $tourIds */
    public function deletePendingForUserTours(string $userIdentifier, array $tourIds): int
    {
        if ($tourIds === []) {
            return 0;
        }

        return $this->conn()->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE %s = ? AND %s = ? AND %s IN (?)',
                $this->table(), $this->col('userIdentifier'), $this->col('status'), $this->tourCol(),
            ),
            [$userIdentifier, ProgressStatus::PENDING->value, $tourIds],
            [null, null, ArrayParameterType::INTEGER],
        );
    }

    /** Usuário excluído: remove todo o histórico dele (inclusive LGPD). */
    public function deleteAllForUser(string $userIdentifier): int
    {
        return $this->conn()->executeStatement(
            sprintf('DELETE FROM %s WHERE %s = ?', $this->table(), $this->col('userIdentifier')),
            [$userIdentifier],
        );
    }

    /**
     * Usuário trocou de identificador (ex.: e-mail). Se o novo identificador já
     * tiver linha para algum tour, a linha antiga desse tour é descartada.
     */
    public function renameUser(string $old, string $new): int
    {
        if ($old === $new) {
            return 0;
        }

        return $this->conn()->transactional(function (Connection $conn) use ($old, $new): int {
            $taken = array_keys($this->fetchStatusesForUser($new));

            if ($taken !== []) {
                $conn->executeStatement(
                    sprintf('DELETE FROM %s WHERE %s = ? AND %s IN (?)', $this->table(), $this->col('userIdentifier'), $this->tourCol()),
                    [$old, $taken],
                    [null, ArrayParameterType::INTEGER],
                );
            }

            return $conn->executeStatement(
                sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $this->table(), $this->col('userIdentifier'), $this->col('userIdentifier')),
                [$new, $old],
            );
        });
    }

    private function conn(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    private function table(): string
    {
        return $this->getClassMetadata()->getTableName();
    }

    private function col(string $field): string
    {
        return $this->getClassMetadata()->getColumnName($field);
    }

    private function tourCol(): string
    {
        return $this->getClassMetadata()->getSingleAssociationJoinColumnName('tour');
    }
}
