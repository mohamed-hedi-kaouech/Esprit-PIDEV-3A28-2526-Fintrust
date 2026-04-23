<?php

namespace App\Form\Front;

use App\Dto\InternationalTransferData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InternationalTransferType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sourceWalletId', ChoiceType::class, [
                'label' => 'Wallet source',
                'choices' => $options['wallet_choices'],
                'placeholder' => 'Selectionnez un wallet',
                'required' => true,
                'attr' => [
                    'class' => 'form-select ft-input',
                ],
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Montant',
                'scale' => 2,
                'required' => true,
                'attr' => [
                    'placeholder' => '0.00',
                    'step' => '0.01',
                    'min' => '0.01',
                    'inputmode' => 'decimal',
                    'class' => 'form-control ft-input',
                ],
            ])
            ->add('sourceCurrency', ChoiceType::class, [
                'label' => 'Devise source',
                'choices' => $options['currency_choices'],
                'placeholder' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-select ft-input',
                ],
                'help' => 'La devise source est controlee par le wallet selectionne.',
            ])
            ->add('targetCurrency', ChoiceType::class, [
                'label' => 'Devise cible',
                'choices' => $options['currency_choices'],
                'placeholder' => 'Selectionnez la devise cible',
                'required' => true,
                'attr' => [
                    'class' => 'form-select ft-input',
                ],
            ])
            ->add('beneficiary', TextType::class, [
                'label' => 'Beneficiaire',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Ex: John Doe - IBAN / compte externe',
                    'maxlength' => 120,
                    'autocomplete' => 'off',
                    'class' => 'form-control ft-input',
                ],
            ])
            ->add('reference', TextType::class, [
                'label' => 'Reference',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Ex: facture INV-2026-001',
                    'maxlength' => 255,
                    'autocomplete' => 'off',
                    'class' => 'form-control ft-input',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InternationalTransferData::class,
            'wallet_choices' => [],
            'currency_choices' => [],
        ]);

        $resolver->setAllowedTypes('wallet_choices', 'array');
        $resolver->setAllowedTypes('currency_choices', 'array');
    }
}
