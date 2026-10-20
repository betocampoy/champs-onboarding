<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Seed;

use BetoCampoy\Champs\Onboarding\Entity\Tour;
use BetoCampoy\Champs\Onboarding\Entity\TourStep;
use BetoCampoy\Champs\Onboarding\Enum\StepPosition;
use BetoCampoy\Champs\Onboarding\Enum\TourTrigger;
use BetoCampoy\Champs\Onboarding\Repository\TourRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Tours versionados no repositório do projeto: um YAML por tour (seed.path).
 *
 * - export: tour do banco → YAML (para versionar um tour ajustado na tela do admin)
 * - import: YAML → banco. Cria só o que não existe (pelo slug), sempre INATIVO: o admin
 *   testa e ativa. Tour existente fica como está (o ajuste do admin vale), a não ser com
 *   $update: aí os dados e os passos vêm do YAML, mas "ativo" e o progresso continuam.
 *
 * Formato (chaves em snake_case; só slug, name, start_route e steps são obrigatórias):
 *
 *   slug: whatsapp-conversa
 *   name: Navegando numa conversa
 *   start_route: modulo_whatsapp_atendimento_index
 *   trigger: first_access            # first_access | new_feature | manual
 *   condition_anchor: wa-conversa-item
 *   required_attribute: modulo:whatsapp
 *   steps:
 *     - title: Conversas
 *       content: Clique numa conversa para abrir.
 *       anchor: wa-conversa-item
 *       placement: bottom
 */
final class TourSeeder
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const SKIPPED = 'skipped';
    public const INVALID = 'invalid';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TourRepository $tours,
        private readonly ValidatorInterface $validator,
        private readonly RouterInterface $router,
        #[Autowire(param: 'champs_onboarding.seed.path')] private readonly string $path,
    ) {
    }

    /** Pasta dos YAML (config champs_onboarding.seed.path). */
    public function path(): string
    {
        return $this->path;
    }

    /** @return list<string> arquivos .yaml/.yml da pasta, em ordem alfabética */
    public function files(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }
        $dir = rtrim($this->path, '/\\');
        $files = [...(glob($dir . '/*.yaml') ?: []), ...(glob($dir . '/*.yml') ?: [])]; // GLOB_BRACE não existe em todo SO
        sort($files);

        return $files;
    }

    public function export(Tour $tour): array
    {
        $data = [
            'slug' => $tour->getSlug(),
            'name' => $tour->getName(),
            'description' => $tour->getDescription(),
            'start_route' => $tour->getStartRoute(),
            'sample_url' => $tour->getSampleUrl(),
            'trigger' => $tour->getTrigger()->value,
            'condition_anchor' => $tour->getConditionAnchor(),
            'published_at' => $tour->getPublishedAt()?->format('Y-m-d H:i:s'),
            'required_attribute' => $tour->getRequiredAttribute(),
            'priority' => $tour->getPriority() ?: null,
            'mandatory' => $tour->isMandatory() ?: null,
            'monitored' => $tour->isMonitored() ?: null,
            'steps' => [],
        ];

        $steps = $tour->getSteps()->toArray();
        usort($steps, static fn (TourStep $a, TourStep $b) => $a->getPosition() <=> $b->getPosition());
        foreach ($steps as $step) {
            $data['steps'][] = array_filter([
                'title' => $step->getTitle(),
                'content' => $step->getContent(),
                'anchor' => $step->getAnchor(),
                'placement' => $step->getPlacement() === StepPosition::AUTO ? null : $step->getPlacement()->value,
                'route' => $step->getRoute(),
                'help_url' => $step->getHelpUrl(),
                'help_label' => $step->getHelpLabel(),
                'advance_on_click' => $step->isAdvanceOnClick() ?: null,
                'require_input' => $step->isRequireInput() ?: null,
                'required_text' => $step->getRequiredText(),
                'close_modal' => $step->isCloseModal() ?: null,
            ], static fn ($v) => $v !== null);
        }

        // sem null/false/0: o YAML fica só com o que difere do padrão
        return array_filter($data, static fn ($v) => $v !== null);
    }

    public function exportYaml(Tour $tour): string
    {
        return Yaml::dump($this->export($tour), 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /** @return array{status: string, slug: string, errors: list<string>} */
    public function importFile(string $file, bool $update = false, bool $dryRun = false): array
    {
        try {
            $data = Yaml::parseFile($file);
        } catch (\Throwable $e) {
            return ['status' => self::INVALID, 'slug' => basename($file), 'errors' => [$e->getMessage()]];
        }

        return $this->import(is_array($data) ? $data : [], $update, $dryRun);
    }

    /** @return array{status: string, slug: string, errors: list<string>} */
    public function import(array $data, bool $update = false, bool $dryRun = false): array
    {
        $slug = (string) ($data['slug'] ?? '');
        $result = static fn (string $status, array $errors = []) => ['status' => $status, 'slug' => $slug, 'errors' => $errors];

        $errors = $this->structureErrors($data);
        if ($errors !== []) {
            return $result(self::INVALID, $errors);
        }

        $existing = $this->tours->findOneBy(['slug' => $slug]);
        if ($existing !== null && !$update) {
            return $result(self::SKIPPED);
        }

        $tour = $existing ?? (new Tour($slug, '', ''))->setActive(false);
        $this->fill($tour, $data);
        $errors = $this->validate($tour);

        if ($errors !== [] || $dryRun) {
            if ($existing !== null) {
                $this->em->refresh($existing); // descarta o que o fill mudou
            }

            return $result($errors !== [] ? self::INVALID : ($existing ? self::UPDATED : self::CREATED), $errors);
        }

        $this->em->persist($tour);
        $this->em->flush();

        return $result($existing ? self::UPDATED : self::CREATED);
    }

    private function structureErrors(array $data): array
    {
        $errors = [];
        foreach (['slug', 'name', 'start_route'] as $key) {
            if (!is_string($data[$key] ?? null) || trim($data[$key]) === '') {
                $errors[] = sprintf('"%s" é obrigatório.', $key);
            }
        }
        if (!is_array($data['steps'] ?? null) || $data['steps'] === [] || !array_is_list($data['steps'])) {
            $errors[] = '"steps" precisa ser uma lista com pelo menos um passo.';
        } else {
            foreach ($data['steps'] as $i => $step) {
                if (!is_array($step) || !is_string($step['title'] ?? null) || !is_string($step['content'] ?? null)) {
                    $errors[] = sprintf('Passo %d: "title" e "content" são obrigatórios.', $i + 1);
                }
            }
        }
        if (isset($data['trigger']) && TourTrigger::tryFrom((string) $data['trigger']) === null) {
            $errors[] = sprintf('trigger "%s" inválido (first_access, new_feature, manual).', $data['trigger']);
        }
        foreach ((array) ($data['steps'] ?? []) as $i => $step) {
            if (is_array($step) && isset($step['placement']) && StepPosition::tryFrom((string) $step['placement']) === null) {
                $errors[] = sprintf('Passo %d: placement "%s" inválido.', $i + 1, $step['placement']);
            }
        }
        if (isset($data['published_at']) && !$this->toDate($data['published_at'])) {
            $errors[] = 'published_at inválido (use AAAA-MM-DD HH:MM:SS).';
        }

        return $errors;
    }

    private function fill(Tour $tour, array $data): void
    {
        $tour->setSlug((string) $data['slug'])
            ->setName((string) $data['name'])
            ->setDescription($data['description'] ?? null)
            ->setStartRoute((string) $data['start_route'])
            ->setSampleUrl($data['sample_url'] ?? null)
            ->setTrigger(TourTrigger::from((string) ($data['trigger'] ?? TourTrigger::FIRST_ACCESS->value)))
            ->setConditionAnchor($data['condition_anchor'] ?? null)
            ->setPublishedAt(isset($data['published_at']) ? $this->toDate($data['published_at']) : null)
            ->setRequiredAttribute($data['required_attribute'] ?? null)
            ->setPriority((int) ($data['priority'] ?? 0))
            ->setMandatory((bool) ($data['mandatory'] ?? false))
            ->setMonitored((bool) ($data['monitored'] ?? false));

        foreach ($tour->getSteps()->toArray() as $old) {
            $tour->getSteps()->removeElement($old); // orphanRemoval apaga
        }

        foreach ($data['steps'] as $row) {
            $tour->addStep(
                (new TourStep((string) $row['title'], (string) $row['content']))
                    ->setAnchor($row['anchor'] ?? null)
                    ->setPlacement(StepPosition::from((string) ($row['placement'] ?? StepPosition::AUTO->value)))
                    ->setRoute($row['route'] ?? null)
                    ->setHelpUrl($row['help_url'] ?? null)
                    ->setHelpLabel($row['help_label'] ?? null)
                    ->setAdvanceOnClick((bool) ($row['advance_on_click'] ?? false))
                    ->setRequireInput((bool) ($row['require_input'] ?? false))
                    ->setRequiredText($row['required_text'] ?? null)
                    ->setCloseModal((bool) ($row['close_modal'] ?? false))
            );
        }
    }

    /** Mesmas regras do cadastro do admin (entidades + rotas existentes + obrigatório sem condição). */
    private function validate(Tour $tour): array
    {
        $errors = [];
        foreach ($this->validator->validate($tour) as $v) {
            $errors[] = $v->getPropertyPath() . ': ' . $v->getMessage();
        }

        $routes = $this->router->getRouteCollection();
        if ($routes->get($tour->getStartRoute()) === null) {
            $errors[] = sprintf('start_route "%s" não existe.', $tour->getStartRoute());
        }
        if ($tour->isMandatory() && $tour->isConditional()) {
            $errors[] = 'Tour obrigatório não pode ter condition_anchor.';
        }

        foreach ($tour->getSteps() as $step) {
            $n = $step->getPosition() + 1;
            foreach ($this->validator->validate($step) as $v) {
                $errors[] = sprintf('Passo %d, %s: %s', $n, $v->getPropertyPath(), $v->getMessage());
            }
            if ($step->getRoute() !== null && $routes->get($step->getRoute()) === null) {
                $errors[] = sprintf('Passo %d: route "%s" não existe.', $n, $step->getRoute());
            }
        }

        return $errors;
    }

    private function toDate(mixed $value): ?\DateTimeImmutable
    {
        if (is_int($value)) {
            return (new \DateTimeImmutable())->setTimestamp($value); // YAML converte data sem aspas em timestamp
        }
        try {
            return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
