<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\MessageHandler;

use BetoCampoy\Champs\Onboarding\Manager\MonitoringManager;
use BetoCampoy\Champs\Onboarding\Message\SyncMonitoredTour;
use BetoCampoy\Champs\Onboarding\Repository\TourRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncMonitoredTourHandler
{
    public function __construct(
        private readonly TourRepository $tours,
        private readonly MonitoringManager $monitoring,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncMonitoredTour $message): void
    {
        $tour = $this->tours->find($message->tourId);

        if ($tour === null || !$this->monitoring->isEnabled()) {
            return; // tour excluído antes do processamento: as linhas já caíram em cascata
        }

        $result = $this->monitoring->syncTour($tour);

        $this->logger->info('champs_onboarding: tour monitorado sincronizado', [
            'tour' => $tour->getSlug(),
            ...$result,
        ]);
    }
}
