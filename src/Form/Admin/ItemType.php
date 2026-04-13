<?php

namespace App\Form\Admin;

use App\Entity\Categorie\Categorie;
use App\Entity\Categorie\Item;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('libelle', TextType::class, [
                'label' => 'Libellé de l\'Item',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Entrez le libellé de l\'item'],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Le libellé de l\'item est obligatoire']),
                    new Assert\Length([
                        'min' => 3,
                        'max' => 150,
                        'minMessage' => 'Le libellé doit contenir au moins {{ limit }} caractères',
                        'maxMessage' => 'Le libellé ne peut pas dépasser {{ limit }} caractères'
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^[a-zA-Z\s\-_\(\)]+$/',
                        'message' => 'Le libellé ne peut contenir que des lettres, espaces, tirets, underscores et parenthèses'
                    ])
                ]
            ])
            ->add('montant', NumberType::class, [
                'label' => 'Montant (€)',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Entrez le montant'],
                'invalid_message' => 'Le montant doit être un nombre valide',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Le montant est obligatoire']),
                    new Assert\Positive(['message' => 'Le montant doit être un nombre positif']),
                    new Assert\GreaterThan([
                        'value' => 0,
                        'message' => 'Le montant doit être supérieur à 0'
                    ]),
                    new Assert\LessThanOrEqual([
                        'value' => 500000,
                        'message' => 'Le montant ne peut pas dépasser 500 000 €'
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^[0-9]+(\.[0-9]{1,2})?$/',
                        'message' => 'Le montant ne peut contenir que des chiffres et un point décimal (maximum 2 décimales)'
                    ])
                ]
            ])
            ->add('categorieRel', EntityType::class, [
                'label' => 'Catégorie',
                'class' => Categorie::class,
                'choice_label' => 'nomCategorie',
                'attr' => ['class' => 'form-control'],
                'placeholder' => 'Sélectionnez une catégorie',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'La sélection d\'une catégorie est obligatoire'])
                ]
            ]);

        // Validation personnalisée : vérifier que le montant ne dépasse pas le budget de la catégorie
        $builder->addEventListener(
            \Symfony\Component\Form\FormEvents::POST_SUBMIT,
            function (\Symfony\Component\Form\FormEvent $event) {
                $form = $event->getForm();
                $montant = $form->get('montant')->getData();
                $categorie = $form->get('categorieRel')->getData();

                if ($montant !== null && $categorie !== null) {
                    $budgetPrevu = $categorie->getBudgetPrevu();

                    if ($montant > $budgetPrevu) {
                        $form->get('montant')->addError(new \Symfony\Component\Form\FormError(
                            'Le montant de l\'item (' . number_format($montant, 2, ',', ' ') . ' €) ne peut pas dépasser le budget prévu de la catégorie (' . number_format($budgetPrevu, 2, ',', ' ') . ' €)'
                        ));
                    }
                }
            }
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Item::class,
        ]);
    }
}
