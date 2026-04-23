<?php
// src/Form/Loan/LoanSimulatorType.php
namespace App\Form\Loan;

use App\Entity\Loan\Loan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class LoanSimulatorType extends AbstractType
{
    private const MAX_AMOUNTS = [
        'PERSONNEL' => 25000,
        'VOITURE' => 50000,
        'LOGEMENT' => 200000,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('loanType', HiddenType::class, [
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Choice(['choices' => ['PERSONNEL', 'VOITURE', 'LOGEMENT']]),
                ],
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Montant du Prêt (TND)',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(),
                ],
                'attr' => [
                    'min' => 1000,
                    'step' => 100,
                ],
            ])
            ->add('duration', RangeType::class, [
                'label' => 'Durée de Remboursement (mois)',
                'attr' => [
                    'min' => 6,
                    'max' => 36,
                    'step' => 6,
                ],
                'constraints' => [
                    new Assert\Range([
                        'min' => 6,
                        'max' => 36,
                    ]),
                ],
            ])
            ->add('interestRate', HiddenType::class, [
                'data' => 8.25,
                'empty_data' => '8.25',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(),
                ],
            ]);

        // Dynamic validation based on loan type
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $form = $event->getForm();
            
            $loanType = $data['loanType'] ?? 'PERSONNEL';
            $maxAmount = self::MAX_AMOUNTS[$loanType] ?? 25000;

            $form->add('amount', NumberType::class, [
                'label' => 'Montant du Prêt (TND)',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(),
                    new Assert\LessThanOrEqual([
                        'value' => $maxAmount,
                        'message' => "Le montant maximum pour ce type de prêt est {$maxAmount} TND",
                    ]),
                ],
                'attr' => [
                    'min' => 1000,
                    'max' => $maxAmount,
                    'step' => 100,
                    'placeholder' => "Max: {$maxAmount} TND",
                ],
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Loan::class,
        ]);
    }
}