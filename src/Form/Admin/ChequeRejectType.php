<?php

namespace App\Form\Admin;

use App\Entity\Wallet\Cheque;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChequeRejectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('motifRejet', TextareaType::class, [
            'label' => 'Motif de refus',
            'required' => true,
            'attr' => [
                'rows' => 5,
                'class' => 'form-control ft-input',
                'placeholder' => 'Expliquez clairement pourquoi ce cheque est refuse.',
                'required' => true,
                'maxlength' => 255,
                'minlength' => 5,
            ],
            'constraints' => [
                new NotBlank([
                    'message' => 'Le motif de refus est obligatoire.',
                ]),
                new Length([
                    'min' => 5,
                    'minMessage' => 'Le motif doit contenir au moins {{ limit }} caracteres.',
                    'max' => 255,
                ]),
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
