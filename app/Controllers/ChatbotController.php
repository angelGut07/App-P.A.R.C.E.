<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Chatbot\ChatbotService;
use App\Core\Http\RequestValidator;
use App\Core\Http\ResponseFormatter;
use App\Core\Http\ErrorHandler;

/**
 * POST /api/chatbot: body {messages: [{role, content}]} → {reply}
 */
class ChatbotController extends Controller
{
    public function reply(Request $request): Response
    {
        try {
            $json = RequestValidator::parseJsonBody($request);
            if (!$json['valid']) {
                return ResponseFormatter::error($json['error'], null, $json['statusCode']);
            }

            if (!is_array($json['data']['messages'] ?? null)) {
                return ResponseFormatter::validationError(['messages' => 'El historial de mensajes es requerido']);
            }

            $reply = (new ChatbotService())->reply($json['data']['messages']);

            return ResponseFormatter::success(['reply' => $reply], 'OK', 200);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }
}
