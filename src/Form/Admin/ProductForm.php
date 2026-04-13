<?php

// src/Form/ProductType.php
namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use App\Entity\Product\Product;

class ProductForm extends AbstractType{
    public function buildForm(FormBuilderInterface $builder, array $options): void{
        $categories = [
            'COMPTE_COURANT', 'COMPTE_EPARGNE', 'COMPTE_PREMIUM',
            'COMPTE_JEUNE', 'COMPTE_ENTREPRISE',
            'CARTE_DEBIT', 'CARTE_CREDIT', 'CARTE_PREMIUM',
            'CARTE_VIRTUELLE',
            'EPARGNE_CLASSIQUE', 'EPARGNE_LOGEMENT', 'DEPOT_A_TERME',
            'PLACEMENT_INVESTISSEMENT',
            'ASSURANCE_VIE', 'ASSURANCE_HABITATION', 'ASSURANCE_VOYAGE',
        ];

        $builder
            ->add('category', ChoiceType::class, [
                'choices'     => array_combine($categories, $categories),
                'placeholder' => 'Sélectionner une catégorie',
            ])
            ->add('price', NumberType::class, [
                'scale' => 2,
                'attr'  => ['placeholder' => '0'],
            ])
            ->add('description', TextareaType::class, [
                'attr' => ['placeholder' => 'Description'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
        ]);
    }
}