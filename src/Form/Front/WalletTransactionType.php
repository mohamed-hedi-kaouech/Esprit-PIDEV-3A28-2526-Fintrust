<?php

namespace App\Form\Front;

use App\Entity\Wallet\Transaction;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

class WalletTransactionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Type de transaction',
                'choices' => [
                    'Depot' => 'depot',
                    'Retrait' => 'retrait',
                ],
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le type de transaction est obligatoire.']),
                ],
                'placeholder' => 'Choisir un type',
            ])
            ->add('montant', NumberType::class, [
                'label' => 'Montant',
                'scale' => 2,
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le montant est obligatoire.']),
                    new Positive(['message' => 'Le montant doit etre superieur a zero.']),
                ],
                'attr' => [
                    'placeholder' => '0.00',
                    'step' => '0.01',
                    'min' => '0.01',
                    'required' => true,
                    'inputmode' => 'decimal',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'constraints' => [
                    new Length([
                        'max' => 500,
                        'maxMessage' => 'La description ne doit pas depasser 500 caracteres.',
                    ]),
                ],
                'attr' => [
                    'rows' => 4,
                    'maxlength' => 500,
                    'placeholder' => 'Ex: Depot initial, retrait DAB...',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transaction::class,
        ]);
    }
}
