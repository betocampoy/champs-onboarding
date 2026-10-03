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
 * - syncUser(), removeUser(), renameUser(): ciclo de vida do usuário
 *
 * Só mexe em linhas PENDING. Histórico de quem já abriu o tour nunca é apagado,
 * exceto quando o usuário é excluído.
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
     * @return array{added: int, removed: int}
     */
    public function syncTour(Tour $tour): array
    {
        $tourId = (int) $tour->getId();
        $existing = $this->progress->fetchStatusesForTour($tourId);
        $pending = array_keys(array_filter($existing, static fn (string $s) => $s === ProgressStatus::PENDING->value));

        if (!$tour->isActive() || !$tour->isMonitored()) {
            return ['added' => 0, 'removed' => $this->progress->deletePendingForTourUsers($tourId, $pending)];
        }

        $eligible = [];
        $batch = [];
        $added = 0;

        foreach ($this->users->iterateUsers() as $user) {
            if (!$this->users->isEligible($user, $tour)) {
                continue;
            }

            $id = $user->getUserIdentifier();
            $eligible[$id] = true;

            if (!isset($existing[$id])) {
                $batch[] = $id;
            }

            if (count($batch) >= $this->users->getBatchSize()) {
                $added += $this->progress->insertPending($tourId, $batch);
                $batch = [];
            }
        }

        $added += $this->progress->insertPending($tourId, $batch);

        $toRemove = array_values(array_filter($pending, static fn (string $id) => !isset($eligible[$id])));
        $removed = $this->progress->deletePendingForTourUsers($tourId, $toRemove);

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Usuário criado ou com roles alteradas: cria PENDING nos tours que
     * ele passou a ver e remove PENDING dos que deixou de ver.
     */
    public function syncUser(UserInterface $user): void
    {
        $id = $user->getUserIdentifier();
        $statuses = $this->progress->fetchStatusesForUser($id);
        $lostAccess = [];

        foreach ($this->tours->findMonitoredActive() as $tour) {
            $tourId = (int) $tour->getId();
            $status = $statuses[$tourId] ?? null;

            if ($this->users->isEligible($user, $tour)) {
                if ($status === null) {
                    $this->progress->insertPending($tourId, [$id]);
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
