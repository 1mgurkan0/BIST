<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'first_options'   => [
                    'label' => 'Yeni Şifre',
                    'attr'  => [
                        'placeholder'  => 'En az 8 karakter',
                        'autocomplete' => 'new-password',
                    ],
                ],
                'second_options'  => [
                    'label' => 'Şifre Tekrar',
                    'attr'  => [
                        'placeholder'  => 'Şifrenizi tekrar girin',
                        'autocomplete' => 'new-password',
                    ],
                ],
                'invalid_message' => 'Şifreler eşleşmiyor.',
                'constraints'     => [
                    new NotBlank(message: 'Şifre boş bırakılamaz.'),
                    new Length(
                        min: 8,
                        max: 4096,
                        minMessage: 'Şifreniz en az {{ limit }} karakter olmalıdır.',
                    ),
                    new Regex(
                        pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
                        message: 'Şifreniz en az bir büyük harf, bir küçük harf ve bir rakam içermelidir.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}