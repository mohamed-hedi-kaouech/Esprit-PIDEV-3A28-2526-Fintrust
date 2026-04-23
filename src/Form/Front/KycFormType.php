<?php

namespace App\Form\Front;

use App\Entity\User\Client\Kyc;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FormType Front — Dépôt du dossier KYC.
 *
 * Validation Symfony côté serveur :
 * - CIN : NotBlank + Length (via entité)
 * - Adresse : NotBlank (via entité)
 * - Date de naissance : NotNull + LessThan('-18 years') (via entité)
 * - Documents : NotBlank + File (taille, type MIME)
 */
class KycFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Numéro CIN
            ->add('cin', TextType::class, [
                'label' => 'Numéro CIN',
                'attr'  => [
                    'placeholder' => 'Ex : 12345678',
                    'maxlength'   => 8,
                    'inputmode'   => 'numeric',
                ],
            ])
            // Adresse complète
            ->add('adresse', TextareaType::class, [
                'label' => 'Adresse complète',
                'attr'  => [
                    'rows'        => 3,
                    'placeholder' => 'Numéro, Rue, Ville, Code postal',
                ],
            ])
            // Date de naissance (widget HTML5 date)
            ->add('dateNaissance', DateType::class, [
                'label'  => 'Date de naissance',
                'widget' => 'single_text',
                'attr'   => ['max' => (new \DateTime('-18 years'))->format('Y-m-d')],
            ])
            // Fichiers justificatifs (multiple, non mappé)
            ->add('documents', FileType::class, [
                'label'    => 'Pièces justificatives',
                'mapped'   => false,
                'multiple' => true,
                'required' => false,
                'attr'     => [
                    'accept'   => '.jpg,.jpeg,.png,.pdf',
                    'multiple' => 'multiple',
                ],
                'help' => 'Formats acceptés : JPG, PNG, PDF — 5 Mo max par fichier.',
            ])
            ->add('signatureData', HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Kyc::class]);
    }
}
