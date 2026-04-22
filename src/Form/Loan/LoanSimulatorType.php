<?php
// src/Form/Loan/LoanSimulatorType.php
namespace App\Form\Loan;

use App\Entity\Loan\Loan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class LoanSimulatorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('loanType', ChoiceType::class, [
                'label' => 'Type de Prêt',
                'choices' => [
                    'Prêt Personnel' => 'PERSONNEL',
                    'Prêt Voiture' => 'VOITURE',
                    'Prêt Logement' => 'LOGEMENT',
                ],
                'attr' => ['class' => 'loan-type-selector'],
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Montant du Prêt (TND)',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(),
                    new Assert\LessThanOrEqual([
                        'value' => 25000,
                        'message' => 'Le montant maximum est 25 000 TND',
                    ]),
                ],
                'attr' => [
                    'min' => 1000,
                    'max' => 25000,
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
            ->add('interestRate', NumberType::class, [
                'label' => 'Taux d\'intérêt (%)',
                'data' => 8.25,
                'disabled' => true,
                'attr' => ['readonly' => true],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Loan::class,
        ]);
    }
}