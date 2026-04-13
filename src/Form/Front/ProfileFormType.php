<?php

namespace App\Form\Front;

use App\Entity\User\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FormType Front — Modification du profil utilisateur.
 *
 * Les contraintes de validation sont définies directement sur l'entité User
 * via les attributs Assert (NotBlank, Length, Email, Regex).
 */
class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'required' => false,
                'attr'  => [
                    'placeholder' => 'Votre nom',
                ],
            ])
            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'required' => false,
                'attr'  => [
                    'placeholder' => 'Votre prénom',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'required' => false,
                'attr'  => ['placeholder' => 'exemple@fintrust.tn'],
            ])
            ->add('numTel', TelType::class, [
                'label'    => 'Téléphone',
                'required' => false,
                'attr'     => [
                    'placeholder' => '+216 XX XXX XXX',
                ],
            ])
            ->add('preferredLanguage', ChoiceType::class, [
                'label' => 'Langue',
                'required' => false,
                'choices' => [
                    'Français' => User::LANGUAGE_FR,
                    'English' => User::LANGUAGE_EN,
                    'العربية' => User::LANGUAGE_AR,
                ],
            ])
            ->add('themeMode', ChoiceType::class, [
                'label' => 'Mode d’affichage',
                'required' => false,
                'choices' => [
                    'Clair' => User::THEME_LIGHT,
                    'Sombre' => User::THEME_DARK,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
