<?php

namespace App\Models\Upload;

use App\Core\DomainException;

/**
 * Servicio de carga de archivos
 *
 * Guarda imágenes y PDFs (foto de perfil, evidencias, documentos del
 * vehículo, licencia y certificación del mecánico) en `public/uploads/` y
 * devuelve su URL pública. La carpeta se puede cambiar con UPLOADS_PATH (en
 * Render se monta ahí un disco persistente).
 */
class UploadService
{
    public const CATEGORIES = ['profile', 'evidence', 'document'];

    private const MAX_BYTES = 5 * 1024 * 1024;

    private const MIME_EXTENSIONS = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** Solo los documentos admiten PDF; perfil y evidencias son imágenes. */
    private const PDF_CATEGORIES = ['document'];

    /**
     * Guarda un archivo subido y devuelve su URL pública.
     *
     * @param array  $file     Entrada de $_FILES
     * @param string $category Una de CATEGORIES
     * @param int    $userId   Usuario que sube el archivo (forma parte del nombre)
     * @return array            ['url' => string, 'originalFilename' => string, 'size' => int]
     * @throws DomainException  400 si el archivo no es válido
     */
    public function store(array $file, string $category, int $userId): array
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new DomainException('Categoría de archivo inválida', 400);
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new DomainException('No se recibió el archivo o la carga fue interrumpida', 400);
        }

        if ($file['size'] > self::MAX_BYTES) {
            throw new DomainException('El archivo supera el máximo de 5 MB', 400);
        }

        $mime = mime_content_type($file['tmp_name']) ?: '';
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;
        if ($extension === null || ($extension === 'pdf' && !in_array($category, self::PDF_CATEGORIES, true))) {
            throw new DomainException('Tipo de archivo no permitido. Usa JPG, PNG o WebP (o PDF para documentos)', 400);
        }

        $directory = self::basePath() . '/' . $category;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException("No se pudo crear la carpeta de cargas: {$directory}");
        }

        $filename = sprintf('%d_%s.%s', $userId, bin2hex(random_bytes(12)), $extension);
        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
            throw new \RuntimeException('No se pudo guardar el archivo subido');
        }

        return [
            'url'              => self::publicUrl("{$category}/{$filename}"),
            'originalFilename' => mb_substr(basename((string)($file['name'] ?? $filename)), 0, 255),
            'size'             => (int)$file['size'],
        ];
    }

    /**
     * Indica si una URL apunta a un archivo subido a esta plataforma.
     */
    public static function isUploadUrl(string $url): bool
    {
        return str_starts_with($url, self::publicUrl(''));
    }

    private static function basePath(): string
    {
        return rtrim($_ENV['UPLOADS_PATH'] ?? (BASE_PATH . '/public/uploads'), '/');
    }

    private static function publicUrl(string $relative): string
    {
        return rtrim($_ENV['APP_URL'] ?? '', '/') . '/uploads/' . $relative;
    }
}
