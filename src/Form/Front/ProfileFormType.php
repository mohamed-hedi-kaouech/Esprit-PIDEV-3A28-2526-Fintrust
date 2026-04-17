<?php

namespace App\Form\Front;

use App\Entity\User\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'required' => false,
                'attr' => ['placeholder' => 'Votre nom'],
            ])
            ->add('prenom', TextType::class, [
                'label' => 'Prenom',
                'required' => false,
                'attr' => ['placeholder' => 'Votre prenom'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'required' => false,
                'attr' => ['placeholder' => 'exemple@fintrust.tn'],
            ])
            ->add('numTel', TelType::class, [
                'label' => 'Telephone',
                'required' => false,
                'attr' => ['placeholder' => '+216 XX XXX XXX'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
