<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label'       => 'Ad',
                'attr'        => ['placeholder' => 'Adınız', 'autocomplete' => 'given-name'],
                'constraints' => [
                    new NotBlank(message: 'Adınızı girin.'),
                    new Length(
                        min: 2,
                        max: 100,
                        minMessage: 'Adınız en az {{ limit }} karakter olmalıdır.',
                        maxMessage: 'Adınız en fazla {{ limit }} karakter olabilir.',
                    ),
                    new Regex(
                        pattern: '/^[\p{L}\s\-\']+$/u',
                        message: 'Ad yalnızca harf içerebilir.',
                    ),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label'       => 'Soyad',
                'attr'        => ['placeholder' => 'Soyadınız', 'autocomplete' => 'family-name'],
                'constraints' => [
                    new NotBlank(message: 'Soyadınızı girin.'),
                    new Length(
                        min: 2,
                        max: 100,
                        minMessage: 'Soyadınız en az {{ limit }} karakter olmalıdır.',
                        maxMessage: 'Soyadınız en fazla {{ limit }} karakter olabilir.',
                    ),
                    new Regex(
                        pattern: '/^[\p{L}\s\-\']+$/u',
                        message: 'Soyad yalnızca harf içerebilir.',
                    ),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-posta',
                'attr'  => ['placeholder' => 'ornek@mail.com', 'autocomplete' => 'email'],
                'constraints' => [
                    new NotBlank(message: 'E-posta adresinizi girin.'),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'first_options'   => [
                    'label' => 'Şifre',
                    'attr'  => ['placeholder' => 'En az 8 karakter', 'autocomplete' => 'new-password'],
                ],
                'second_options'  => [
                    'label' => 'Şifre Tekrar',
                    'attr'  => ['placeholder' => 'Şifrenizi tekrar girin', 'autocomplete' => 'new-password'],
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
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped'      => false,
                'label'       => 'Kullanım koşullarını okudum ve kabul ediyorum.',
                'constraints' => [
                    new IsTrue(message: 'Devam etmek için kullanım koşullarını kabul etmelisiniz.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}