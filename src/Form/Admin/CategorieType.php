<?php

namespace App\Form\Admin;

use App\Entity\Categorie\Categorie;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CategorieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomCategorie', TextType::class, [
                'label' => 'Nom de la Catégorie',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Entrez le nom de la catégorie'],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Le nom de la catégorie est obligatoire']),
                    new Assert\Length([
                        'min' => 3,
                        'max' => 100,
                        'minMessage' => 'Le nom doit contenir au moins {{ limit }} caractères',
                        'maxMessage' => 'Le nom ne peut pas dépasser {{ limit }} caractères'
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^[a-zA-Z\s\-_\(\)]+$/',
                        'message' => 'Le nom ne peut contenir que des lettres, espaces, tirets, underscores et parenthèses'
                    ])
                ]
            ])
            ->add('budgetPrevu', NumberType::class, [
                'label' => 'Budget Prévu (DT)',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Entrez le budget prévu'],
                'invalid_message' => 'Le budget prévu doit être un nombre valide',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Le budget prévu est obligatoire']),
                    new Assert\Positive(['message' => 'Le budget prévu doit être un nombre positif']),
                    new Assert\GreaterThan([
                        'value' => 0,
                        'message' => 'Le budget prévu doit être supérieur à 0'
                    ]),
                    new Assert\LessThanOrEqual([
                        'value' => 1000000,
                        'message' => 'Le budget prévu ne peut pas dépasser 1 000 000 DT'
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^[0-9]+(\.[0-9]{1,2})?$/',
                        'message' => 'Le budget prévu ne peut contenir que des chiffres et un point décimal (maximum 2 décimales)'
                    ])
                ]
            ])
            ->add('seuilAlerte', NumberType::class, [
                'label' => 'Seuil d\'Alerte (DT)',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Entrez le seuil d\'alerte'],
                'invalid_message' => 'Le seuil d\'alerte doit être un nombre valide',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Le seuil d\'alerte est obligatoire']),
                    new Assert\Positive(['message' => 'Le seuil d\'alerte doit être un nombre positif']),
                    new Assert\GreaterThan([
                        'value' => 0,
                        'message' => 'Le seuil d\'alerte doit être supérieur à 0'
                    ]),
                    new Assert\LessThanOrEqual([
                        'value' => 1000000,
                        'message' => 'Le seuil d\'alerte ne peut pas dépasser 1 000 000 DT'
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^[0-9]+(\.[0-9]{1,2})?$/',
                        'message' => 'Le seuil d\'alerte ne peut contenir que des chiffres et un point décimal (maximum 2 décimales)'
                    ])
                ]
            ]);

        // Validation personnalisée : seuil d'alerte doit être inférieur au budget prévu
        $builder->addEventListener(
            \Symfony\Component\Form\FormEvents::POST_SUBMIT,
            function (\Symfony\Component\Form\FormEvent $event) {
                $form = $event->getForm();
                $budgetPrevu = $form->get('budgetPrevu')->getData();
                $seuilAlerte = $form->get('seuilAlerte')->getData();

                if ($budgetPrevu !== null && $seuilAlerte !== null && $seuilAlerte >= $budgetPrevu) {
                    $form->get('seuilAlerte')->addError(new \Symfony\Component\Form\FormError(
                        'Le seuil d\'alerte doit être inférieur au budget prévu'
                    ));
                }
            }
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Categorie::class,
        ]);
    }
}
