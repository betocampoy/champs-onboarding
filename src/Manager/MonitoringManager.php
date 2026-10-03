<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Manager;

use BetoCampoy\Champs\Onboarding\Entity\Tour;
use BetoCampoy\Champs\Onboarding\Enum\ProgressStatus;
use BetoCampoy\Champs\Onboarding\Monitoring\MonitoredUserProvider;
use BetoCampoy\Champs\Onboarding\Repository\TourProgressRepository;
use BetoCampoy\Champs\Onboarding\Repository\TourRepository;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Mantém as linhas PENDING dos tours monitorados em dia:
 * - syncTour(): carga inicial / ressincronização de um tour inteiro
 * - syncUsers(): ressincroniza usuários (todos ou de um segmento) após uma
 *   mudança que o bundle não enxerga (ex.: o tenant contratou um módulo)
 * - syncUser(), removeUser(), renameUser(): ciclo de vida do usuário
 *
 * Só apaga linhas PENDING. Histórico de quem já abriu o tour nunca é apagado,
 * exceto quando o usuário é excluído. O segmento é corrigido em todas as linhas.
 */
final class MonitoringManager
{
    public function __construct(
        private readonly TourRepository $tours,
        private readonly TourProgressRepository $progress,
        private readonly MonitoredUserProvider $users,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->users->isEnabled();
    }

    /**
     * Sincroniza um tour com a base de usuários.
     * Tour desativado ou não monitorado: só remove as linhas PENDING.
     *
     * @return array{added: int, removed: int, segmentsFixed: int}
     */
    public function syncTour(Tour $tour): array
    {
        $tourId = (int) $tour->getId();
        $existing = $this->progress->fetchRowsForTour($tourId);
        $pending = array_map('strval', array_keys(array_filter(
            $existing,
            static fn (array $row) => $row['status'] === ProgressStatus::PENDING->value,
        )));

        if (!$tour->isActive() || !$tour->isMonitored()) {
            return ['added' => 0, 'removed' => $this->progress->deletePendingForTourUsers($tourId, $pending), 'segmentsFixed' => 0];
        }

        $eligible = [];
        $batch = [];
        $wrongSegment = [];
        $added = 0;

        foreach ($this->users->iterateUsers() as $user) {
            $id = $user->getUserIdentifier();
            $segment = $this->users->segmentOf($user);

            if (isset($existing[$id]) && $existing[$id]['segment'] !== $segment) {
                $wrongSegment[$id] = $segment;
            }

            if (!$this->users->isEligible($user, $tour)) {
                continue;
            }

            $eligible[$id] = true;

            if (!isset($existing[$id])) {
                $batch[$id] = $segment;
            }

            if (count($batch) >= $this->users->getBatchSize()) {
                $added += $this->progress->insertPending($tourId, $batch);
                $batch = [];
            }
        }

        $added += $this->progress->insertPending($tourId, $batch);

        $toRemove = array_values(array_filter($pending, static fn (string $id) => !isset($eligible[$id])));
        $removed = $this->progress->deletePendingForTourUsers($tourId, $toRemove);

        // Linhas removidas acima não precisam de correção.
        $wrongSegment = array_diff_key($wrongSegment, array_flip($toRemove));

        return ['added' => $added, 'removed' => $removed, 'segmentsFixed' => $this->progress->updateSegments($tourId, $wrongSegment)];
    }

    /**
     * Ressincroniza os usuários (de um segmento, ou todos) em todos os tours monitorados.
     *
     * @return int usuários processados
     */
    public function syncUsers(?string $segment = null): int
    {
        $count = 0;

        foreach ($this->users->iterateUsers() as $user) {
            if ($segment !== null && $this->users->segmentOf($user) !== $segment) {
                continue;
            }

            $this->syncUser($user);
            $count++;
        }

        return $count;
    }

    /**
     * Usuário criado ou com um campo vigiado alterado: cria PENDING nos tours
     * que ele passou a ver, remove PENDING dos que deixou de ver e atualiza o
     * segmento de todas as linhas dele.
     */
    public function syncUser(UserInterface $user): void
    {
        $id = $user->getUserIdentifier();
        $segment = $this->users->segmentOf($user);
        $statuses = $this->progress->fetchStatusesForUser($id);
        $lostAccess = [];

        $this->progress->updateSegmentForUser($id, $segment);

        foreach ($this->tours->findMonitoredActive() as $tour) {
            $tourId = (int) $tour->getId();
            $status = $statuses[$tourId] ?? null;

            if ($this->users->isEligible($user, $tour)) {
                if ($status === null) {
                    $this->progress->insertPending($tourId, [$id => $segment]);
                }
            } elseif ($status === ProgressStatus::PENDING->value) {
                $lostAccess[] = $tourId;
            }
        }

        $this->progress->deletePendingForUserTours($id, $lostAccess);
    }

    public function removeUser(string $userIdentifier): void
    {
        $this->progress->deleteAllForUser($userIdentifier);
    }

    public function renameUser(string $old, string $new): void
    {
        $this->progress->renameUser($old, $new);
    }
}
