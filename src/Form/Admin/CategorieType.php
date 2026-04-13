<?php

namespace App\Form\Admin;

use App\Entity\Categorie\Categorie;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategorieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomCategorie', TextType::class, [
                'label' => 'Nom de la categorie',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Entrez le nom de la categorie',
                ],
            ])
            ->add('budgetPrevu', NumberType::class, [
                'label' => 'Budget prevu',
                'required' => false,
                'invalid_message' => 'Le budget doit etre un nombre valide',
                'attr' => [
                    'placeholder' => 'Entrez le budget prevu',
                ],
            ])
            ->add('seuilAlerte', NumberType::class, [
                'label' => 'Seuil d alerte',
                'required' => false,
                'invalid_message' => 'Le seuil doit etre un nombre valide',
                'attr' => [
                    'placeholder' => 'Entrez le seuil d alerte',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Categorie::class,
        ]);
    }
}
