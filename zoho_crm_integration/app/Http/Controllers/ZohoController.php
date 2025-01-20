<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ZohoController extends Controller
{
    private $tokenFile = 'zoho_tokens.json';

    /**
     * Загружает токены из JSON-файла
     */
    private function getTokens()
    {
        $tokensFilePath = base_path($this->tokenFile);

        if (!file_exists($tokensFilePath)) {
            \Log::error('Zoho tokens file not found!');
            return ['access_token' => null, 'refresh_token' => null];
        }

        $tokens = json_decode(file_get_contents($tokensFilePath), true);
        return $tokens ?: ['access_token' => null, 'refresh_token' => null];
    }

    /**
     * Сохраняет токены в JSON-файл
     */
    private function saveTokens($accessToken, $refreshToken = null)
    {
        $tokensFilePath = base_path($this->tokenFile);
    
        $tokens = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken ?? $this->getTokens()['refresh_token']
        ];
    
        \Log::info('Saving Zoho tokens:', $tokens);
    
        try {
            file_put_contents($tokensFilePath, json_encode($tokens, JSON_PRETTY_PRINT));
            \Log::info('Zoho tokens successfully saved.');
        } catch (\Exception $e) {
            \Log::error('Failed to save Zoho tokens! Error: ' . $e->getMessage());
        }
    }

    /**
     * Проверяет, истек ли токен
     */
    private function isAccessTokenExpired()
    {
        return Storage::missing($this->tokenFile) || (time() - Storage::lastModified($this->tokenFile)) > 1800;
    }

    /**
     * Обновляет Access Token через Refresh Token
     */
    public function refreshAccessToken()
    {
        $tokens = $this->getTokens();

        $response = Http::asForm()->post(env('ZOHO_ACCOUNTS_URL') . '/oauth/v2/token', [
            'grant_type' => 'refresh_token',
            'client_id' => env('ZOHO_CLIENT_ID'),
            'client_secret' => env('ZOHO_CLIENT_SECRET'),
            'refresh_token' => $tokens['refresh_token'],
            'scope' => env('ZOHO_SCOPE')
        ]);

        $data = $response->json();

        if (!isset($data['access_token'])) {
            return response()->json([
                'error' => 'Failed to refresh access token',
                'details' => $data
            ], 400);
        }

        $this->saveTokens($data['access_token']);

        return response()->json([
            'message' => 'Access token refreshed!',
            'access_token' => $data['access_token']
        ]);
    }

    /**
     * OAuth Callback
     */
    public function handleOAuthCallback(Request $request)
    {
        if (!$request->has('code')) {
            return response()->json(['error' => 'Authorization code not found'], 400);
        }

        $response = Http::asForm()->post(env('ZOHO_ACCOUNTS_URL') . '/oauth/v2/token', [
            'grant_type' => 'authorization_code',
            'client_id' => env('ZOHO_CLIENT_ID'),
            'client_secret' => env('ZOHO_CLIENT_SECRET'),
            'redirect_uri' => env('ZOHO_REDIRECT_URI'),
            'code' => $request->query('code'),
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $this->saveTokens($data['access_token'], $data['refresh_token'] ?? null);

            return response()->json([
                'message' => 'Access token received!',
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? 'Already exists',
                'expires_in' => $data['expires_in']
            ]);
        }

        return response()->json(['error' => 'Failed to get access token', 'details' => $response->json()], 400);
    }

    /**
     * Получение модулей Zoho CRM
     */
    public function getModules()
    {
        if ($this->isAccessTokenExpired()) {
            $this->refreshAccessToken();
        }

        $tokens = $this->getTokens();
        $response = Http::withHeaders([
            'Authorization' => "Zoho-oauthtoken " . $tokens['access_token'],
        ])->get(env('ZOHO_API_URL') . '/crm/v7/settings/modules');

        return $response->json();
    }

    /**
     * Создание сделки и аккаунта в Zoho CRM
     */
    public function createDeal(Request $request)
    {
        if ($this->isAccessTokenExpired()) {
            $this->refreshAccessToken();
        }

        $validated = $request->validate([
            'dealName' => ['required', 'string', 'max:255'],
            'dealStage' => ['required', 'string', 'max:255'],
            'accountName' => ['required', 'string', 'max:255'],
            'accountWebsite' => ['required', 'url', 'max:255'],
            'accountPhone' => ['required', 'regex:/^\+?\d{10,15}$/'],
        ]);

        $tokens = $this->getTokens();
        $apiUrl = env('ZOHO_API_URL', 'https://www.zohoapis.eu');

        // Создание аккаунта
        $accountResponse = Http::withHeaders([
            'Authorization' => "Zoho-oauthtoken " . $tokens['access_token'],
            'Content-Type' => 'application/json'
        ])->post("$apiUrl/crm/v7/Accounts", [
            'data' => [[
                'Account_Name' => $validated['accountName'],
                'Website' => $validated['accountWebsite'],
                'Phone' => $validated['accountPhone']
            ]]
        ]);

        $accountData = $accountResponse->json();
        \Log::info('Zoho Create Account Response:', $accountData);

        if (!isset($accountData['data'][0]['details']['id'])) {
            return response()->json(['error' => 'Ошибка при создании аккаунта', 'details' => $accountData], 400);
        }

        $accountId = $accountData['data'][0]['details']['id'];

        // Создание сделки
        $dealResponse = Http::withHeaders([
            'Authorization' => "Zoho-oauthtoken " . $tokens['access_token'],
            'Content-Type' => 'application/json'
        ])->post("$apiUrl/crm/v7/Deals", [
            'data' => [[
                'Deal_Name' => $validated['dealName'],
                'Stage' => $validated['dealStage'],
                'Account_Name' => [
                    'id' => $accountId
                ]
            ]]
        ]);

        $dealData = $dealResponse->json();
        \Log::info('Zoho Create Deal Response:', $dealData);

        if (!isset($dealData['data'][0]['details']['id'])) {
            return response()->json(['error' => 'Ошибка при создании сделки', 'details' => $dealData], 400);
        }

        return response()->json([
            'message' => 'Сделка и аккаунт успешно созданы в Zoho CRM!',
            'account_id' => $accountId,
            'deal_id' => $dealData['data'][0]['details']['id']
        ], 201);
    }
}