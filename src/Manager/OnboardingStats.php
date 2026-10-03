<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Manager;

use BetoCampoy\Champs\Onboarding\Entity\Tour;
use BetoCampoy\Champs\Onboarding\Enum\ProgressStatus;
use BetoCampoy\Champs\Onboarding\Repository\TourProgressRepository;
use BetoCampoy\Champs\Onboarding\Repository\TourRepository;

/**
 * Números do dashboard. Calcula em PHP a partir das linhas de progresso,
 * para não depender de funções de data específicas de cada banco.
 *
 * - completionRate: concluídos ÷ quem abriu o tour
 * - coverageRate:   concluídos ÷ todos os elegíveis (só faz sentido em tour monitorado)
 */
final class OnboardingStats
{
    public function __construct(
        private readonly TourRepository $tours,
        private readonly TourProgressRepository $progress,
    ) {
    }

    /** @return list<array> um resumo por tour */
    public function overview(): array
    {
        return array_map(
            fn (Tour $tour) => ['tour' => $tour, ...$this->summarize($this->normalize($this->progress->findStatsRows($tour)), $tour)],
            $this->tours->findAllWithSteps(),
        );
    }

    /** Detalhe de um tour: resumo + funil + atividade recente + quem não abriu. */
    public function forTour(Tour $tour): array
    {
        $rows = $this->normalize($this->progress->findStatsRows($tour));

        return [
            'tour' => $tour,
            ...$this->summarize($rows, $tour),
            'funnel' => $this->funnel($tour, $rows),
            'recent' => $this->progress->findRecent($tour),
            'notStartedList' => $tour->isMonitored() ? $this->progress->findNotStarted($tour) : [],
        ];
    }

    private function summarize(array $rows, Tour $tour): array
    {
        $counts = array_fill_keys(array_map(static fn (ProgressStatus $s) => $s->value, ProgressStatus::cases()), 0);
        $durations = [];
        $reopened = 0;

        foreach ($rows as $r) {
            $counts[$r['status']->value]++;

            if ($r['status'] === ProgressStatus::COMPLETED && $r['startedAt'] !== null && $r['finishedAt'] !== null) {
                $durations[] = $r['finishedAt']->getTimestamp() - $r['startedAt']->getTimestamp();
            }
            if ($r['views'] > 1) {
                $reopened++;
            }
        }

        $total = count($rows);
        $started = $total - $counts[ProgressStatus::PENDING->value];
        $completed = $counts[ProgressStatus::COMPLETED->value];

        return [
            'monitored' => $tour->isMonitored(),
            'total' => $total,
            'notStarted' => $counts[ProgressStatus::PENDING->value],
            'started' => $started,
            'inProgress' => $counts[ProgressStatus::IN_PROGRESS->value],
            'completed' => $completed,
            'skipped' => $counts[ProgressStatus::SKIPPED->value],
            'completionRate' => $started > 0 ? round($completed / $started * 100, 1) : 0.0,
            'coverageRate' => $tour->isMonitored() && $total > 0 ? round($completed / $total * 100, 1) : null,
            'avgSeconds' => $durations !== [] ? (int) round(array_sum($durations) / count($durations)) : null,
            'reopened' => $reopened,
        ];
    }

    /**
     * Funil (só quem abriu o tour): quantos chegaram a cada passo,
     * quantos pularam ali e quantos estão parados nele.
     */
    private function funnel(Tour $tour, array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn (array $r) => $r['status']->isStarted()));
        $last = $tour->getLastPosition();
        $total = count($rows);
        $funnel = [];

        foreach ($tour->getSteps() as $step) {
            $pos = $step->getPosition();
            $reached = $skippedHere = $stuckHere = 0;

            foreach ($rows as $r) {
                $at = $r['status'] === ProgressStatus::COMPLETED ? $last : $r['currentStep'];

                if ($at >= $pos) {
                    $reached++;
                }
                if ($at === $pos && $r['status'] === ProgressStatus::SKIPPED) {
                    $skippedHere++;
                }
                if ($at === $pos && $r['status'] === ProgressStatus::IN_PROGRESS) {
                    $stuckHere++;
                }
            }

            $funnel[] = [
                'position' => $pos,
                'title' => $step->getTitle(),
                'reached' => $reached,
                'reachedRate' => $total > 0 ? round($reached / $total * 100, 1) : 0.0,
                'skippedHere' => $skippedHere,
                'stuckHere' => $stuckHere,
            ];
        }

        return $funnel;
    }

    /** Garante tipos (enum/int) independente de como o driver devolveu. */
    private function normalize(array $rows): array
    {
        return array_map(static fn (array $r) => [
            'status' => $r['status'] instanceof ProgressStatus ? $r['status'] : ProgressStatus::from((string) $r['status']),
            'currentStep' => (int) $r['currentStep'],
            'views' => (int) $r['views'],
            'startedAt' => $r['startedAt'],
            'finishedAt' => $r['finishedAt'],
        ], $rows);
    }
}
