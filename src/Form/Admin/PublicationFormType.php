<?php

namespace App\Form\Admin;

use App\Entity\Publication\Publication;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PublicationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, [
                'label' => 'Titre',
                'required' => false,
                'attr' => ['placeholder' => 'Titre de la publication'],
            ])
            ->add('contenu', TextareaType::class, [
                'label' => 'Contenu',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Contenu complet de la publication',
                    'rows' => 10,
                ],
            ])
            ->add('categorie', ChoiceType::class, [
                'label' => 'Categorie',
                'required' => false,
                'placeholder' => 'Choisir une categorie',
                'choices' => [
                    'Finance' => Publication::CATEGORY_FINANCE,
                    'Assurance' => Publication::CATEGORY_ASSURANCE,
                    'Tech' => Publication::CATEGORY_TECH,
                    'Fintech' => Publication::CATEGORY_FINTECH,
                    'Epargne' => Publication::CATEGORY_EPARGNE,
                    'Investissement' => Publication::CATEGORY_INVESTISSEMENT,
                    'Credit' => Publication::CATEGORY_CREDIT,
                    'Cybersecurite' => Publication::CATEGORY_CYBERSECURITE,
                    'Conformite' => Publication::CATEGORY_CONFORMITE,
                    'Reglementation' => Publication::CATEGORY_REGLEMENTATION,
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('statut', ChoiceType::class, [
                'label' => 'Statut',
                'required' => false,
                'choices' => [
                    'Brouillon' => Publication::STATUS_BROUILLON,
                    'Publie' => Publication::STATUS_PUBLIE,
                ],
                'attr' => ['class' => 'form-select'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Publication::class,
        ]);
    }
}
