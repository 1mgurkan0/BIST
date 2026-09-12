<?php

namespace App\Service;

use App\Entity\Media;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class MediaUploader
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly string $uploadDir,
        private readonly SluggerInterface $slugger,
    ) {
    }

    public function upload(UploadedFile $file): Media
    {
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Desteklenmeyen dosya türü. Sadece JPG, PNG, WEBP, GIF yükleyebilirsin.');
        }

        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = $this->slugger->slug($originalName)->lower();
        $fileName = $safeName . '-' . uniqid() . '.' . $file->guessExtension();

        $file->move($this->uploadDir, $fileName);

        $fullPath = $this->uploadDir . '/' . $fileName;
        $dimensions = @getimagesize($fullPath);

        $media = new Media();
        $media->setPath('uploads/media/' . $fileName);
        $media->setOriginalName($file->getClientOriginalName());
        $media->setMimeType($file->getMimeType());
        $media->setSize((int) filesize($fullPath));

        if ($dimensions !== false) {
            $media->setWidth($dimensions[0]);
            $media->setHeight($dimensions[1]);
        }

        return $media;
    }
}
