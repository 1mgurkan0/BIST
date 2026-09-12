<?php

namespace App\Form;

use App\Entity\BlogCategory;
use App\Entity\BlogPost;
use App\Entity\BlogTag;
use App\Entity\Media;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BlogPostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Başlık',
            ])
            ->add('slug', TextType::class, [
                'label' => 'URL (slug)',
                'required' => false,
                'help' => 'Boş bırakırsan başlıktan otomatik üretilir.',
            ])
            ->add('excerpt', TextareaType::class, [
                'label' => 'Özet',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'İçerik',
                'attr' => ['rows' => 14],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Durum',
                'choices' => [
                    'Taslak' => BlogPost::STATUS_DRAFT,
                    'Yayında' => BlogPost::STATUS_PUBLISHED,
                    'Zamanlanmış' => BlogPost::STATUS_SCHEDULED,
                ],
            ])
            ->add('category', EntityType::class, [
                'label' => 'Kategori',
                'class' => BlogCategory::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Kategori seç',
            ])
            ->add('tags', EntityType::class, [
                'label' => 'Etiketler',
                'class' => BlogTag::class,
                'choice_label' => 'name',
                'required' => false,
                'multiple' => true,
            ])
            ->add('metaTitle', TextType::class, [
                'label' => 'SEO Başlık',
                'required' => false,
            ])
            ->add('metaDescription', TextareaType::class, [
                'label' => 'SEO Açıklama',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('canonicalUrl', TextType::class, [
                'label' => 'Canonical URL',
                'required' => false,
            ])
            ->add('focusKeyword', TextType::class, [
                'label' => 'Odak Kelime',
                'required' => false,
            ])
            ->add('ogImage', EntityType::class, [
                'class' => Media::class,
                'choice_label' => 'id', // Veya medya tablosundaki başlık alanı adı örn: 'name' / 'filename'
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BlogPost::class,
        ]);
    }
}