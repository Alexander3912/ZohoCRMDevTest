<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ZohoController extends Controller
{
    public function handleOAuthCallback(Request $request)
    {
        if (!$request->has('code')) {
            return response()->json(['error' => 'Authorization code not found'], 400);
        }
    
        $authorizationCode = $request->query('code');
    
        $response = Http::asForm()->post('https://accounts.zoho.eu/oauth/v2/token', [
            'grant_type' => 'authorization_code',
            'client_id' => env('ZOHO_CLIENT_ID'),
            'client_secret' => env('ZOHO_CLIENT_SECRET'),
            'redirect_uri' => env('ZOHO_REDIRECT_URI'),
            'code' => $authorizationCode,
        ]);
    
        if ($response->successful()) {
            $data = $response->json();
    
            file_put_contents(base_path('.env'), str_replace(
                'ZOHO_ACCESS_TOKEN=' . env('ZOHO_ACCESS_TOKEN'),
                'ZOHO_ACCESS_TOKEN=' . $data['access_token'],
                file_get_contents(base_path('.env'))
            ));
    
            file_put_contents(base_path('.env'), str_replace(
                'ZOHO_REFRESH_TOKEN=' . env('ZOHO_REFRESH_TOKEN'),
                'ZOHO_REFRESH_TOKEN=' . ($data['refresh_token'] ?? env('ZOHO_REFRESH_TOKEN')),
                file_get_contents(base_path('.env'))
            ));
    
            return response()->json([
                'message' => 'Access token received!',
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? 'Already exists',
                'expires_in' => $data['expires_in']
            ]);
        } else {
            return response()->json(['error' => 'Failed to get access token', 'details' => $response->json()], 400);
        }
    }

    public function refreshAccessToken()
    {
        $response = Http::asForm()->post(env('ZOHO_ACCOUNTS_URL') . '/oauth/v2/token', [
            'grant_type' => 'refresh_token',
            'client_id' => env('ZOHO_CLIENT_ID'),
            'client_secret' => env('ZOHO_CLIENT_SECRET'),
            'refresh_token' => env('ZOHO_REFRESH_TOKEN'),
            'scope' => env('ZOHO_SCOPE')
        ]);
    
        $data = $response->json();
    
        if (!isset($data['access_token'])) {
            return response()->json([
                'error' => 'Failed to refresh access token',
                'details' => $data
            ], 400);
        }

        file_put_contents(base_path('.env'), str_replace(
            'ZOHO_ACCESS_TOKEN=' . env('ZOHO_ACCESS_TOKEN'),
            'ZOHO_ACCESS_TOKEN=' . $data['access_token'],
            file_get_contents(base_path('.env'))
        ));
    
        return response()->json([
            'message' => 'Access token refreshed!',
            'access_token' => $data['access_token']
        ]);
    }

    private function isAccessTokenExpired()
    {
        $lastUpdated = filemtime(base_path('.env'));
        $expiresIn = 1800;
    
        return (time() - $lastUpdated) > $expiresIn;
    }

    public function getModules()
    {
        if ($this->isAccessTokenExpired()) {
            $this->refreshAccessToken();
        }

        $response = Http::withHeaders([
            'Authorization' => "Zoho-oauthtoken " . env('ZOHO_ACCESS_TOKEN'),
        ])->get('https://www.zohoapis.eu/crm/v7/settings/modules');
    
        return $response->json();
    }

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
        ], [
            'dealName.required' => 'Название сделки обязательно.',
            'dealStage.required' => 'Этап сделки обязателен.',
            'accountName.required' => 'Название аккаунта обязательно.',
            'accountWebsite.required' => 'Введите URL веб-сайта.',
            'accountWebsite.url' => 'Некорректный формат URL.',
            'accountPhone.required' => 'Введите номер телефона.',
            'accountPhone.regex' => 'Некорректный формат телефона (пример: +1234567890).'
        ]);

        $apiUrl = env('ZOHO_API_URL', 'https://www.zohoapis.eu');

        $accountResponse = Http::withHeaders([
            'Authorization' => "Zoho-oauthtoken " . env('ZOHO_ACCESS_TOKEN'),
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
            return response()->json([
                'error' => 'Ошибка при создании аккаунта',
                'details' => $accountData
            ], 400);
        }

        $accountId = $accountData['data'][0]['details']['id'];

        $dealResponse = Http::withHeaders([
            'Authorization' => "Zoho-oauthtoken " . env('ZOHO_ACCESS_TOKEN'),
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
            return response()->json([
                'error' => 'Ошибка при создании сделки',
                'details' => $dealData
            ], 400);
        }
    
        return response()->json([
            'message' => 'Сделка и аккаунт успешно созданы в Zoho CRM!',
            'account_id' => $accountId,
            'deal_id' => $dealData['data'][0]['details']['id']
        ], 201);
    }
}