<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Command;

use BetoCampoy\Champs\Onboarding\Seed\TourSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * php bin/console champs:onboarding:import                       → todos os YAML de seed.path
 * php bin/console champs:onboarding:import caminho/tour.yaml      → só esses arquivos
 * php bin/console champs:onboarding:import --dry-run              → só confere
 * php bin/console champs:onboarding:import whatsapp.yaml --update → sobrescreve o tour existente
 *
 * Tour novo entra INATIVO; tour existente é pulado (sem --update). Pode rodar em todo deploy.
 */
#[AsCommand(name: 'champs:onboarding:import', description: 'Cria os tours versionados em YAML que ainda não existem no banco.')]
final class ImportToursCommand extends Command
{
    public function __construct(private readonly TourSeeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('files', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Arquivos YAML (padrão: todos de champs_onboarding.seed.path)')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Sobrescreve dados e passos do tour que já existe (mantém "ativo" e o progresso)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Só valida, não grava');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $files = $input->getArgument('files') ?: $this->seeder->files();

        if ($files === []) {
            $io->note(sprintf('Nenhum YAML em %s.', $this->seeder->path()));
            return Command::SUCCESS;
        }

        $rows = [];
        $failed = false;
        foreach ($files as $file) {
            if (!is_file($file)) {
                $rows[] = [basename($file), '<error>arquivo não encontrado</error>', ''];
                $failed = true;
                continue;
            }

            $r = $this->seeder->importFile($file, (bool) $input->getOption('update'), (bool) $input->getOption('dry-run'));
            $failed = $failed || $r['status'] === TourSeeder::INVALID;
            $rows[] = [
                $r['slug'],
                match ($r['status']) {
                    TourSeeder::CREATED => '<info>criado (inativo)</info>',
                    TourSeeder::UPDATED => '<info>atualizado</info>',
                    TourSeeder::SKIPPED => 'já existe',
                    default => '<error>inválido</error>',
                },
                implode("\n", $r['errors']),
            ];
        }

        $io->table(['Tour', 'Resultado', 'Erros'], $rows);
        if ($input->getOption('dry-run')) {
            $io->note('--dry-run: nada foi gravado.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
