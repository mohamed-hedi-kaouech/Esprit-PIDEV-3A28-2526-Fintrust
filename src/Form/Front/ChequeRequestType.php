<?php

namespace App\Form\Front;

use App\Entity\Wallet\Cheque;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

class ChequeRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('beneficiaire', TextType::class, [
                'label' => 'Beneficiaire',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le beneficiaire est obligatoire.']),
                    new Length([
                        'max' => 100,
                        'maxMessage' => 'Le beneficiaire ne doit pas depasser 100 caracteres.',
                    ]),
                ],
                'attr' => [
                    'placeholder' => 'Nom du beneficiaire',
                    'maxlength' => 100,
                    'required' => true,
                ],
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Cheque::class,
        ]);
    }
}
