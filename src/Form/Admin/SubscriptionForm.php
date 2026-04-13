<?php

// src/Form/SubscriptionType.php
namespace App\Form\Admin;

use App\Entity\Product\ProductSubscription;
use App\Entity\Product\Product;
use App\Entity\User\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SubscriptionForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('clientUser', EntityType::class, [
                'class'        => User::class,
                'choice_label' => fn(User $u) => $u->getNom() . ' ' . $u->getPrenom(),
                'placeholder'  => 'Choisissez le client',
                'label'        => 'Client *',
            ])
            ->add('productObj', EntityType::class, [
                'class'        => Product::class,
                'choice_label' => fn(Product $p) => $p->getProductId() . ' — ' . $p->getCategory(),
                'label'        => 'Produit *',
            ])
            ->add('type', ChoiceType::class, [
                'choices'  => array_combine(
                    ['MONTHLY', 'ANNUAL', 'TRANSACTION', 'ONE_TIME'],
                    ['MONTHLY', 'ANNUAL', 'TRANSACTION', 'ONE_TIME']
                ),
                'label'    => "Type d'abonnement *",
            ])
            ->add('subscriptionDate', DateType::class, [
                'widget' => 'single_text',
                'label'  => 'Date de souscription *',
            ])
            ->add('expirationDate', DateType::class, [
                'widget' => 'single_text',
                'label'  => "Date d'expiration *",
            ])
            ->add('status', ChoiceType::class, [
                'choices' => array_combine(
                    ['DRAFT', 'ACTIVE', 'SUSPENDED', 'CLOSED'],
                    ['DRAFT', 'ACTIVE', 'SUSPENDED', 'CLOSED']
                ),
                'label'   => 'Statut *',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProductSubscription::class,
        ]);
    }
}