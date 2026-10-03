<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\MessageHandler;

use BetoCampoy\Champs\Onboarding\Manager\MonitoringManager;
use BetoCampoy\Champs\Onboarding\Message\SyncMonitoredUsers;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncMonitoredUsersHandler
{
    public function __construct(
        private readonly MonitoringManager $monitoring,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncMonitoredUsers $message): void
    {
        if (!$this->monitoring->isEnabled()) {
            return;
        }

        $count = $this->monitoring->syncUsers($message->segment);

        $this->logger->info('champs_onboarding: usuários ressincronizados', [
            'segment' => $message->segment,
            'users' => $count,
        ]);
    }
}
