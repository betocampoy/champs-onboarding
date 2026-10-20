<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Command;

use BetoCampoy\Champs\Onboarding\Repository\TourRepository;
use BetoCampoy\Champs\Onboarding\Seed\TourSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * php bin/console champs:onboarding:export whatsapp-conversa   → grava seed.path/whatsapp-conversa.yaml
 * php bin/console champs:onboarding:export --all               → um arquivo por tour
 * php bin/console champs:onboarding:export slug --stdout       → só mostra
 */
#[AsCommand(name: 'champs:onboarding:export', description: 'Grava tours do banco em YAML (para versionar e importar em outro ambiente).')]
final class ExportToursCommand extends Command
{
    public function __construct(
        private readonly TourRepository $tours,
        private readonly TourSeeder $seeder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slugs', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Slugs dos tours')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Todos os tours')
            ->addOption('stdout', null, InputOption::VALUE_NONE, 'Mostra na tela em vez de gravar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tours = $input->getOption('all')
            ? $this->tours->findAllWithSteps()
            : array_map(fn (string $slug) => $this->tours->findOneBy(['slug' => $slug]) ?? $slug, $input->getArgument('slugs'));

        if ($tours === []) {
            $io->error('Informe os slugs ou --all.');
            return Command::INVALID;
        }

        $dir = rtrim($this->seeder->path(), '/\\');
        foreach ($tours as $tour) {
            if (is_string($tour)) {
                $io->error(sprintf('Tour "%s" não existe.', $tour));
                return Command::FAILURE;
            }

            $yaml = $this->seeder->exportYaml($tour);
            if ($input->getOption('stdout')) {
                $output->writeln($yaml);
                continue;
            }

            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $io->error(sprintf('Não consegui criar %s.', $dir));
                return Command::FAILURE;
            }
            file_put_contents($file = $dir . '/' . $tour->getSlug() . '.yaml', $yaml);
            $io->writeln(sprintf('<info>%s</info> → %s', $tour->getSlug(), $file));
        }

        return Command::SUCCESS;
    }
}
