<?php

namespace App\Form\Front;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class VerifyAccountCodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', TextType::class, [
                'label' => 'Adresse e-mail',
                'mapped' => false,
                'required' => false,
                'data' => $options['prefilled_email'],
                'constraints' => [
                    new NotBlank(['message' => "L'adresse e-mail est obligatoire."]),
                ],
                'attr' => [
                    'placeholder' => 'exemple@fintrust.tn',
                    'autocomplete' => 'email',
                ],
            ])
            ->add('code', TextType::class, [
                'label' => 'Code de verification',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new NotBlank(['message' => 'Le code de verification est obligatoire.']),
                    new Length([
                        'min' => 6,
                        'max' => 6,
                        'exactMessage' => 'Le code de verification doit contenir exactement {{ limit }} chiffres.',
                    ]),
                    new Regex([
                        'pattern' => '/^\d{6}$/',
                        'message' => 'Le code de verification doit contenir 6 chiffres.',
                    ]),
                ],
                'attr' => [
                    'placeholder' => '123456',
                    'inputmode' => 'numeric',
                    'autocomplete' => 'one-time-code',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'prefilled_email' => null,
        ]);
    }
}
