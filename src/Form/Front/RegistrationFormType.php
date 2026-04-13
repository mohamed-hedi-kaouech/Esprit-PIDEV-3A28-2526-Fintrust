<?php

namespace App\Form\Front;

use App\Entity\User\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * FormType Front — Inscription d'un nouvel utilisateur CLIENT.
 *
 * Validation côté serveur via les contraintes Symfony Assert.
 * Le champ plainPassword est non mappé (traité manuellement dans le contrôleur).
 */
class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Nom de famille
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'required' => false,
                'attr'  => [
                    'placeholder'  => 'Votre nom de famille',
                    'autocomplete' => 'family-name',
                ],
            ])
            // Prénom
            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'required' => false,
                'attr'  => [
                    'placeholder'  => 'Votre prénom',
                    'autocomplete' => 'given-name',
                ],
            ])
            // Email unique
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'required' => false,
                'attr'  => [
                    'placeholder'  => 'exemple@fintrust.tn',
                    'autocomplete' => 'email',
                ],
            ])
            // Téléphone (optionnel)
            ->add('numTel', TelType::class, [
                'label'    => 'Numéro de téléphone',
                'required' => false,
                'attr'     => [
                    'placeholder' => '+216 XX XXX XXX',
                ],
            ])
            // Mot de passe (double saisie, non mappé)
            ->add('plainPassword', RepeatedType::class, [
                'type'   => PasswordType::class,
                'mapped' => false,
                'required' => false,
                'first_options' => [
                    'label' => 'Mot de passe',
                    'required' => false,
                    'attr'  => [
                        'autocomplete' => 'new-password',
                        'placeholder'  => 'Minimum 8 caractères',
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirmer le mot de passe',
                    'required' => false,
                    'attr'  => [
                        'autocomplete' => 'new-password',
                        'placeholder'  => 'Répétez votre mot de passe',
                    ],
                ],
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                // Contraintes Symfony côté serveur (prioritaires sur HTML5)
                'constraints' => [
                    new NotBlank(['message' => 'Le mot de passe est obligatoire.']),
                    new Length([
                        'min'        => 8,
                        'minMessage' => 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
                        'max'        => 4096,
                    ]),
                    new Regex([
                        'pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
                        'message' => 'Le mot de passe doit contenir au moins une majuscule, une minuscule et un chiffre.',
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
