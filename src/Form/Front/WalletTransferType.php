<?php

namespace App\Form\Front;

use App\Dto\WalletTransferData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
class WalletTransferType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('recipient', TextType::class, [
                'label' => 'Destinataire',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Email ou ID wallet',
                    'autocomplete' => 'off',
                    'maxlength' => 100,
                    'required' => true,
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
                    'required' => true,
                    'inputmode' => 'decimal',
                ],
            ])
            ->add('label', TextareaType::class, [
                'label' => 'Libelle',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'maxlength' => 255,
                    'placeholder' => 'Ex: remboursement, participation, cadeau...',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WalletTransferData::class,
        ]);
    }
}
