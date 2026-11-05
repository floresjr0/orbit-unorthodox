<?php

declare(strict_types=1);

namespace Orbit;

final class MediaService
{
    private const IMAGES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const ATTACHMENTS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
    ];

    public function __construct(private readonly App $app)
    {
    }

    public function storeImage(array $file, string $folder): string
    {
        $stored = $this->validate($file, self::IMAGES, 2 * 1024 * 1024);
        $ext = self::IMAGES[$stored['mime']];
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = storage_path('uploads/' . $folder . '/' . $name);
        $this->write($stored['tmp'], $dest, $stored['mime'], true);

        return $name;
    }

    public function storeAttachment(array $file): array
    {
        $stored = $this->validate($file, self::ATTACHMENTS, 8 * 1024 * 1024);
        $ext = self::ATTACHMENTS[$stored['mime']];
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = storage_path('uploads/attachments/' . $name);
        $this->write($stored['tmp'], $dest, $stored['mime'], isset(self::IMAGES[$stored['mime']]));

        return [
            'stored_name' => $name,
            'mime_type' => $stored['mime'],
            'size_bytes' => (int) filesize($dest),
            'original_name' => $stored['original'],
        ];
    }

    public function path(string $folder, string $name): string
    {
        if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]+$/', $name)) {
            throw new HttpException(404, 'File not found.');
        }

        return storage_path('uploads/' . $folder . '/' . $name);
    }

    public function serve(string $path, string $downloadName, bool $inline): never
    {
        if (!is_file($path)) {
            throw new HttpException(404, 'File not found.');
        }
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName) ?: 'file';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"');
        header('Cache-Control: private, max-age=300');
        readfile($path);
        exit;
    }

    private function validate(array $file, array $allowed, int $max): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'The file could not be uploaded.');
        }
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new HttpException(422, 'The file could not be uploaded.');
        }
        if ((int) ($file['size'] ?? 0) > $max) {
            throw new HttpException(422, 'The file is larger than the allowed size.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $file['tmp_name']) ?: '';
        if (!isset($allowed[$mime])) {
            throw new HttpException(422, 'This file type is not allowed.');
        }
        $original = clean_text($file['name'] ?? 'file', 180);
        if ($original === '') {
            $original = 'file.' . $allowed[$mime];
        }

        return ['tmp' => (string) $file['tmp_name'], 'mime' => $mime, 'original' => $original];
    }

    private function write(string $tmp, string $dest, string $mime, bool $reencode): void
    {
        if ($reencode && function_exists('imagecreatefromstring')) {
            $raw = file_get_contents($tmp);
            $image = $raw !== false ? @imagecreatefromstring($raw) : false;
            if ($image === false) {
                throw new HttpException(422, 'The image could not be read.');
            }
            $ok = match ($mime) {
                'image/png' => imagepng($image, $dest),
                'image/webp' => function_exists('imagewebp') ? imagewebp($image, $dest, 85) : imagejpeg($image, $dest, 88),
                default => imagejpeg($image, $dest, 88),
            };
            imagedestroy($image);
            if (!$ok) {
                throw new HttpException(500, 'The image could not be saved.');
            }
            return;
        }
        if (!move_uploaded_file($tmp, $dest)) {
            throw new HttpException(500, 'The file could not be saved.');
        }
    }
}
