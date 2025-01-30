<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class ZohoTokenMiddleware
{
    private $tokenFile = 'zoho_tokens.json';

    private function getTokenFilePath(): string
    {
        return storage_path('app/' . $this->tokenFile);
    }

    private function getTokens(): array
    {
        $path = $this->getTokenFilePath();

        if (!file_exists($path)) {
            Log::warning('Zoho tokens file not found: ' . $path);
            return ['access_token' => null, 'refresh_token' => null];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            Log::error('Не удалось прочитать файл с токенами: ' . $path);
            return ['access_token' => null, 'refresh_token' => null];
        }

        $tokens = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Ошибка парсинга JSON токенов: ' . json_last_error_msg());
            return ['access_token' => null, 'refresh_token' => null];
        }

        return $tokens ?: ['access_token' => null, 'refresh_token' => null];
    }

    private function saveTokens(array $tokens): bool
    {
        $path = $this->getTokenFilePath();

        try {
            file_put_contents($path, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error('Не удалось сохранить токены в файл: ' . $e->getMessage(), [
                'file' => $path,
            ]);
            return false;
        }

        return true;
    }

    private function refreshAccessToken(): bool
    {
        $tokens = $this->getTokens();

        if (empty($tokens['refresh_token'])) {
            Log::error('Отсутствует refresh_token, невозможно обновить access_token');
            return false;
        }

        $url = env('ZOHO_ACCOUNTS_URL') . '/oauth/v2/token';
        $response = Http::asForm()->post($url, [
            'grant_type'    => 'refresh_token',
            'client_id'     => env('ZOHO_CLIENT_ID'),
            'client_secret' => env('ZOHO_CLIENT_SECRET'),
            'refresh_token' => $tokens['refresh_token'],
        ]);

        if ($response->failed()) {
            Log::error('Не удалось обновить access_token, HTTP-ошибка', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return false;
        }

        $data = $response->json();
        if (empty($data['access_token'])) {
            Log::error('Не пришёл access_token при обновлении', $data);
            return false;
        }

        $tokens['access_token'] = $data['access_token'];

        if (!empty($data['refresh_token'])) {
            $tokens['refresh_token'] = $data['refresh_token'];
        }

        if (!$this->saveTokens($tokens)) {
            return false;
        }

        Log::info('Успешно обновлён access_token');
        return true;
    }

    public function handle(Request $request, Closure $next): Response
    {
        Log::info('ZohoTokenMiddleware запущен');

        $path  = $this->getTokenFilePath();
        $tokens = $this->getTokens();

        $fileExists = file_exists($path);
        $isTooOld   = $fileExists && (time() - filemtime($path)) > 1800;

        if (!$tokens['access_token'] || !$fileExists || $isTooOld) {
            Log::info('Токен отсутствует или истёк, пытаемся обновить...');

            if (!$this->refreshAccessToken()) {
                Log::error('Не удалось обновить токен. Возвращаем 401.');
                return response()->json([
                    'error' => 'Unauthorized: Unable to refresh access token'
                ], 401);
            }
        }

        return $next($request);
    }
}