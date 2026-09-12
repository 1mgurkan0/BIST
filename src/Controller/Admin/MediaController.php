<?php

namespace App\Controller\Admin;

use App\Repository\MediaRepository;
use App\Service\MediaUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/medya', name: 'admin_media_')]
#[IsGranted('ROLE_ADMIN')]
class MediaController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(MediaRepository $repository): Response
    {
        return $this->render('admin/media/index.html.twig', [
            'mediaItems' => $repository->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/yukle', name: 'upload', methods: ['POST'])]
    public function upload(Request $request, MediaUploader $uploader, EntityManagerInterface $em): Response
    {
        $files = $request->files->get('files') ?? [];

        if (!is_array($files)) {
            $files = [$files];
        }

        $uploaded = 0;
        $errors = [];

        foreach ($files as $file) {
            if (!$file) {
                continue;
            }

            try {
                $media = $uploader->upload($file);
                $altText = trim((string) $request->request->get('altText'));
                if ($altText !== '' && count($files) === 1) {
                    $media->setAltText($altText);
                }
                $em->persist($media);
                $uploaded++;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($uploaded > 0) {
            $em->flush();
            $this->addFlash('success', $uploaded . ' dosya yüklendi.');
        }

        foreach ($errors as $error) {
            $this->addFlash('danger', $error);
        }

        return $this->redirectToRoute('admin_media_index');
    }

    #[Route('/{id}/sil', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, MediaRepository $repository, EntityManagerInterface $em): Response
    {
        $media = $repository->find($id);

        if ($media && $this->isCsrfTokenValid('delete-media-' . $media->getId(), $request->request->get('_token'))) {
            $filePath = $this->getParameter('kernel.project_dir') . '/public/' . $media->getPath();
            if (is_file($filePath)) {
                @unlink($filePath);
            }

            $em->remove($media);
            $em->flush();
            $this->addFlash('success', 'Görsel silindi.');
        }

        return $this->redirectToRoute('admin_media_index');
    }
}