<?php

namespace App\Form\Front;

use App\Entity\User\Feedback;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class PublicationCommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('commentaire', TextareaType::class, [
                'label' => 'Votre commentaire',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Partagez votre avis sur cette publication...',
                    'rows' => 4,
                ],
                'constraints' => [
                    new Length([ 'max' => 1000, 'maxMessage' => 'Le commentaire ne peut pas dépasser {{ limit }} caractères.' ]),
                ],
            ])
            ->add('rating', ChoiceType::class, [
                'label' => 'Note',
                'mapped' => false,
                'choices' => [
                    '5 étoiles' => 5,
                    '4 étoiles' => 4,
                    '3 étoiles' => 3,
                    '2 étoiles' => 2,
                    '1 étoile' => 1,
                ],
                'expanded' => true,
                'multiple' => false,
                'constraints' => [
                    new NotBlank([ 'message' => 'Veuillez choisir une note entre 1 et 5 étoiles.' ]),
                    new Choice([ 'choices' => [1, 2, 3, 4, 5], 'message' => 'Note invalide.' ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Feedback::class,
        ]);
    }
}
