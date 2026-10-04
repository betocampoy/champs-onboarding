<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Form;

use BetoCampoy\Champs\Onboarding\Admin\RouteCatalog;
use BetoCampoy\Champs\Onboarding\Entity\Tour;
use BetoCampoy\Champs\Onboarding\Enum\TourTrigger;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TourFormType extends AbstractType
{
    public function __construct(private readonly RouteCatalog $routes)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $routeChoices = $this->routes->choices();
        $current = ($options['data'] ?? null)?->getStartRoute();
        if ($current !== null && $current !== '' && !in_array($current, $routeChoices, true)) {
            $routeChoices[$current] = $current; // rota antiga fora do filtro: não some do select
        }

        $builder
            // empty_data '': campo obrigatório vazio chega como null e quebraria o setter string (o NotBlank é que avisa)
            ->add('name', TextType::class, ['label' => 'admin.tour.name', 'empty_data' => ''])
            ->add('slug', TextType::class, [
                'label' => 'admin.tour.slug',
                'empty_data' => '',
                'help' => 'admin.tour.slug_help',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'admin.tour.description',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('startRoute', ChoiceType::class, [
                'label' => 'admin.tour.start_route',
                'help' => 'admin.tour.start_route_help',
                'choices' => $routeChoices,
                'choice_translation_domain' => false,
                'placeholder' => 'admin.tour.start_route_placeholder',
            ])
            ->add('trigger', EnumType::class, [
                'label' => 'admin.tour.trigger',
                'class' => TourTrigger::class,
                'choice_label' => static fn (TourTrigger $t) => 'admin.trigger.' . $t->value,
                'help' => 'admin.tour.trigger_help',
            ])
            ->add('publishedAt', DateTimeType::class, [
                'label' => 'admin.tour.published_at',
                'help' => 'admin.tour.published_at_help',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('requiredAttribute', TextType::class, [
                'label' => 'admin.tour.required_attribute',
                'help' => 'admin.tour.required_attribute_help',
                'required' => false,
            ])
            ->add('priority', IntegerType::class, [
                'label' => 'admin.tour.priority',
                'help' => 'admin.tour.priority_help',
            ])
            ->add('active', CheckboxType::class, ['label' => 'admin.tour.active', 'required' => false])
            ->add('mandatory', CheckboxType::class, [
                'label' => 'admin.tour.mandatory',
                'help' => 'admin.tour.mandatory_help',
                'required' => false,
            ])
            ->add('monitored', CheckboxType::class, [
                'label' => 'admin.tour.monitored',
                'help' => 'admin.tour.monitored_help',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tour::class,
            'translation_domain' => 'champs_onboarding',
            'empty_data' => static fn (FormInterface $form) => new Tour(
                (string) $form->get('slug')->getData(),
                (string) $form->get('name')->getData(),
                (string) $form->get('startRoute')->getData(),
            ),
        ]);
    }
}
