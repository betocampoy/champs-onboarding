<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Command;

use BetoCampoy\Champs\Onboarding\Manager\MonitoringManager;
use BetoCampoy\Champs\Onboarding\Message\SyncMonitoredTour;
use BetoCampoy\Champs\Onboarding\Repository\TourRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * php bin/console champs:onboarding:sync                 → todos os monitorados
 * php bin/console champs:onboarding:sync importacao-ini  → um tour (mesmo desativado, para limpar)
 * php bin/console champs:onboarding:sync --async         → só enfileira no Messenger
 */
#[AsCommand(name: 'champs:onboarding:sync', description: 'Sincroniza as linhas "não iniciado" dos tours monitorados.')]
final class SyncMonitoredToursCommand extends Command
{
    public function __construct(
        private readonly TourRepository $tours,
        private readonly MonitoringManager $monitoring,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'Slug de um tour específico')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Enfileira no Messenger em vez de rodar agora');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->monitoring->isEnabled()) {
            $io->error('Configure champs_onboarding.monitoring.user_class para usar tours monitorados.');
            return Command::FAILURE;
        }

        $slug = $input->getArgument('slug');
        $tours = $slug !== null
            ? array_filter([$this->tours->findOneBy(['slug' => $slug])])
            : $this->tours->findMonitoredActive();

        if ($tours === []) {
            $io->warning($slug !== null ? sprintf('Tour "%s" não encontrado.', $slug) : 'Nenhum tour monitorado ativo.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($tours as $tour) {
            if ($input->getOption('async')) {
                $this->bus->dispatch(new SyncMonitoredTour((int) $tour->getId()));
                $rows[] = [$tour->getSlug(), 'enfileirado', '-'];
                continue;
            }

            $result = $this->monitoring->syncTour($tour);
            $rows[] = [$tour->getSlug(), $result['added'], $result['removed']];
        }

        $io->table(['Tour', 'Adicionados', 'Removidos'], $rows);

        return Command::SUCCESS;
    }
}
