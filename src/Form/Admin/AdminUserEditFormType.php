<?php

namespace App\Form\Admin;

use App\Entity\User\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;

class AdminUserEditFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'required' => false,
            ])
            ->add('prenom', TextType::class, [
                'label' => 'Prenom',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'required' => false,
            ])
            ->add('numTel', TelType::class, [
                'label' => 'Telephone',
                'required' => false,
                'attr' => [
                    'placeholder' => '+216 12 345 678',
                ],
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Role',
                'required' => false,
                'choices' => [
                    'Client' => User::ROLE_CLIENT,
                    'Administrateur' => User::ROLE_ADMIN,
                ],
                'constraints' => [
                    new Choice([
                        'choices' => [User::ROLE_CLIENT, User::ROLE_ADMIN],
                        'message' => 'Role invalide.',
                    ]),
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Statut du compte',
                'required' => false,
                'choices' => [
                    'En attente' => User::STATUS_EN_ATTENTE,
                    'Actif' => User::STATUS_ACTIF,
                    'Suspendu' => User::STATUS_SUSPENDU,
                ],
                'constraints' => [
                    new Choice([
                        'choices' => [User::STATUS_EN_ATTENTE, User::STATUS_ACTIF, User::STATUS_SUSPENDU],
                        'message' => 'Statut invalide.',
                    ]),
                ],
            ])
            ->add('kycStatus', ChoiceType::class, [
                'label' => 'Statut KYC',
                'required' => false,
                'placeholder' => 'Non soumis',
                'choices' => [
                    'En attente' => User::KYC_EN_ATTENTE,
                    'Approuve' => User::KYC_APPROUVE,
                    'Refuse' => User::KYC_REFUSE,
                ],
                'constraints' => [
                    new Choice([
                        'choices' => [User::KYC_EN_ATTENTE, User::KYC_APPROUVE, User::KYC_REFUSE, null],
                        'message' => 'Statut KYC invalide.',
                    ]),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Nouveau mot de passe',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Length([
                        'min' => 8,
                        'minMessage' => 'Le mot de passe doit contenir au moins {{ limit }} caracteres.',
                        'max' => 255,
                    ]),
                    new Regex([
                        'pattern' => '/^$|^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
                        'message' => 'Le mot de passe doit contenir au moins une majuscule, une minuscule et un chiffre.',
                    ]),
                ],
                'attr' => [
                    'autocomplete' => 'new-password',
                    'placeholder' => 'Laisser vide pour conserver le mot de passe actuel',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
