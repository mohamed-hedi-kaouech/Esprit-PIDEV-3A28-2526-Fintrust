<?php

namespace App\Form\Front;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class InternationalTransferOtpType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('otp_code', TextType::class, [
            'label' => 'Code OTP',
            'mapped' => false,
            'required' => true,
            'constraints' => [
                new Assert\NotBlank(message: 'Le code OTP est obligatoire.'),
                new Assert\Length(
                    min: 4,
                    max: 10,
                    minMessage: 'Le code OTP semble incomplet.',
                    maxMessage: 'Le code OTP est trop long.'
                ),
            ],
            'attr' => [
                'class' => 'form-control ft-input',
                'placeholder' => 'Entrez le code recu',
                'autocomplete' => 'one-time-code',
                'inputmode' => 'numeric',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
