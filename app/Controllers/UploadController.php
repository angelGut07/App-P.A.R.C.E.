<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Upload\UploadService;
use App\Core\Http\ResponseFormatter;
use App\Core\Http\ErrorHandler;

/**
 * Controlador de cargas
 *
 * POST /api/uploads (multipart/form-data): campos `file` y `category`
 * (profile | evidence | document). Devuelve la URL que luego se guarda en el
 * recurso correspondiente (perfil, evidencia, documento del vehículo...).
 */
class UploadController extends Controller
{
    public function store(Request $request): Response
    {
        try {
            $file = $request->file('file');
            if ($file === null) {
                return ResponseFormatter::validationError(['file' => 'El archivo es requerido']);
            }

            $category = (string)($request->input('category') ?? '');
            if (!in_array($category, UploadService::CATEGORIES, true)) {
                return ResponseFormatter::validationError([
                    'category' => 'Categoría requerida: ' . implode(', ', UploadService::CATEGORIES),
                ]);
            }

            $upload = (new UploadService())->store($file, $category, (int)$request->getAttribute('userId'));

            return ResponseFormatter::success(['upload' => $upload], 'Archivo subido correctamente', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }
}
