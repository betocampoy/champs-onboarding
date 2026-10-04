<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Form;

use BetoCampoy\Champs\Onboarding\Admin\RouteCatalog;
use BetoCampoy\Champs\Onboarding\Entity\TourStep;
use BetoCampoy\Champs\Onboarding\Enum\StepPosition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TourStepFormType extends AbstractType
{
    public function __construct(private readonly RouteCatalog $routes)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $routeChoices = $this->routes->choices();
        $current = ($options['data'] ?? null)?->getRoute();
        if ($current !== null && !in_array($current, $routeChoices, true)) {
            $routeChoices[$current] = $current;
        }

        $builder
            // empty_data '': campo obrigatório vazio chega como null e quebraria o setter string (o NotBlank é que avisa)
            ->add('title', TextType::class, ['label' => 'admin.step.title', 'empty_data' => ''])
            ->add('content', TextareaType::class, [
                'label' => 'admin.step.content',
                'empty_data' => '',
                'help' => 'admin.step.content_help',
                'attr' => ['rows' => 4],
            ])
            ->add('anchor', TextType::class, [
                'label' => 'admin.step.anchor',
                'help' => 'admin.step.anchor_help',
                'required' => false,
                'attr' => ['list' => 'champs-onboarding-anchors', 'autocomplete' => 'off'],
            ])
            ->add('placement', EnumType::class, [
                'label' => 'admin.step.placement',
                'class' => StepPosition::class,
                'choice_label' => static fn (StepPosition $p) => 'admin.placement.' . $p->value,
            ])
            ->add('route', ChoiceType::class, [
                'label' => 'admin.step.route',
                'help' => 'admin.step.route_help',
                'required' => false,
                'choices' => $routeChoices,
                'choice_translation_domain' => false,
                'placeholder' => 'admin.step.route_placeholder',
            ])
            ->add('helpUrl', UrlType::class, [
                'label' => 'admin.step.help_url',
                'required' => false,
                'default_protocol' => 'https',
            ])
            ->add('helpLabel', TextType::class, [
                'label' => 'admin.step.help_label',
                'help' => 'admin.step.help_label_help',
                'required' => false,
            ])
            ->add('advanceOnClick', CheckboxType::class, [
                'label' => 'admin.step.advance_on_click',
                'help' => 'admin.step.advance_on_click_help',
                'required' => false,
            ])
            ->add('closeModal', CheckboxType::class, [
                'label' => 'admin.step.close_modal',
                'help' => 'admin.step.close_modal_help',
                'required' => false,
            ])
            ->add('requireInput', CheckboxType::class, [
                'label' => 'admin.step.require_input',
                'help' => 'admin.step.require_input_help',
                'required' => false,
            ])
            ->add('requiredText', TextType::class, [
                'label' => 'admin.step.required_text',
                'help' => 'admin.step.required_text_help',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TourStep::class,
            'translation_domain' => 'champs_onboarding',
            'empty_data' => static fn (FormInterface $form) => new TourStep(
                (string) $form->get('title')->getData(),
                (string) $form->get('content')->getData(),
            ),
        ]);
    }
}
