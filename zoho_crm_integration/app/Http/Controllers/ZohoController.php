<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controller;

class ZohoController extends Controller
{

    private $tokenFile = 'zoho_tokens.json';

    public function __construct()
    {
        $this->middleware('zoho.auth');
    }

    public function handleOAuthCallback(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string'
        ]);

        $url = env('ZOHO_ACCOUNTS_URL') . '/oauth/v2/token';
        $response = Http::asForm()->post($url, [
            'grant_type'    => 'authorization_code',
            'client_id'     => env('ZOHO_CLIENT_ID'),
            'client_secret' => env('ZOHO_CLIENT_SECRET'),
            'redirect_uri'  => env('ZOHO_REDIRECT_URI'),
            'code'          => $validated['code'],
        ]);

        if ($response->failed()) {
            Log::error('Failed to get access token (Auth Callback)', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return response()->json([
                'error'   => 'Failed to get access token',
                'details' => $response->json(),
            ], 400);
        }

        $data = $response->json();
        if (empty($data['access_token'])) {
            Log::error('Access token not returned at callback', $data);
            return response()->json([
                'error'   => 'Access token not returned',
                'details' => $data,
            ], 400);
        }

        $tokens = [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
        ];

        $path = storage_path('app/' . $this->tokenFile);
        try {
            file_put_contents($path, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error('Error writing token file: ' . $e->getMessage(), ['file' => $path]);
            return response()->json(['error' => 'Cannot write token file'], 500);
        }

        return response()->json([
            'message'       => 'Access token received!',
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? 'Already exists',
            'expires_in'    => $data['expires_in'] ?? null,
        ]);
    }

    public function getModules()
    {
        $path = storage_path('app/' . $this->tokenFile);
        $tokens = json_decode(file_get_contents($path), true);

        $url = env('ZOHO_API_URL') . '/crm/v7/settings/modules';
        $response = Http::withHeaders([
            'Authorization' => "Zoho-oauthtoken " . $tokens['access_token'],
        ])->get($url);

        if ($response->failed()) {
            Log::error('getModules запрос провалился', [
                'status' => $response->status(),
                'body'   => $response->body()
            ]);
            return response()->json([
                'error' => 'Failed to fetch modules',
                'details' => $response->json(),
            ], 400);
        }

        return $response->json();
    }

    public function createDeal(Request $request)
    {
        $validated = $request->validate([
            'dealName'       => ['required', 'string', 'max:255'],
            'dealStage'      => ['required', 'string', 'max:255'],
            'accountName'    => ['required', 'string', 'max:255'],
            'accountWebsite' => ['required', 'url', 'max:255'],
            'accountPhone'   => ['required', 'regex:/^\\+?\\d{10,15}$/'],
        ]);

        $path = storage_path('app/' . $this->tokenFile);
        $tokens = json_decode(file_get_contents($path), true);

        $apiUrl = rtrim(env('ZOHO_API_URL', 'https://www.zohoapis.eu'), '/');

        $accountResponse = Http::withHeaders([
            'Authorization' => "Zoho-oauthtoken " . $tokens['access_token'],
            'Content-Type'  => 'application/json'
        ])->post("$apiUrl/crm/v7/Accounts", [
            'data' => [[
                'Account_Name' => $validated['accountName'],
                'Website'      => $validated['accountWebsite'],
                'Phone'        => $validated['accountPhone'],
            ]]
        ]);

        $accountData = $accountResponse->json();
        Log::info('Zoho Create Account Response:', $accountData);

        if ($accountResponse->failed() || empty($accountData['data'][0]['details']['id'])) {
            return response()->json([
                'error'   => 'Ошибка при создании аккаунта',
                'details' => $accountData
            ], 400);
        }

        $accountId = $accountData['data'][0]['details']['id'];

        $dealResponse = Http::withHeaders([
            'Authorization' => "Zoho-oauthtoken " . $tokens['access_token'],
            'Content-Type'  => 'application/json'
        ])->post("$apiUrl/crm/v7/Deals", [
            'data' => [[
                'Deal_Name'    => $validated['dealName'],
                'Stage'        => $validated['dealStage'],
                'Account_Name' => [
                    'id' => $accountId
                ]
            ]]
        ]);

        $dealData = $dealResponse->json();
        Log::info('Zoho Create Deal Response:', $dealData);

        if ($dealResponse->failed() || empty($dealData['data'][0]['details']['id'])) {
            return response()->json([
                'error'   => 'Ошибка при создании сделки',
                'details' => $dealData
            ], 400);
        }

        return response()->json([
            'message'    => 'Сделка и аккаунт успешно созданы в Zoho CRM!',
            'account_id' => $accountId,
            'deal_id'    => $dealData['data'][0]['details']['id']
        ], 201);
    }
}