<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RefreshZohoToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zoho:refresh-token';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically refresh Zoho access token';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $response = Http::asForm()->post(env('ZOHO_ACCOUNTS_URL') . '/oauth/v2/token', [
            'grant_type' => 'refresh_token',
            'client_id' => env('ZOHO_CLIENT_ID'),
            'client_secret' => env('ZOHO_CLIENT_SECRET'),
            'refresh_token' => env('ZOHO_REFRESH_TOKEN'),
            'scope' => env('ZOHO_SCOPE')
        ]);

        $data = $response->json();
        Log::info('Zoho Token Auto-Refresh:', $data);

        if (isset($data['access_token'])) {
            file_put_contents(base_path('.env'), str_replace(
                'ZOHO_ACCESS_TOKEN=' . env('ZOHO_ACCESS_TOKEN'),
                'ZOHO_ACCESS_TOKEN=' . $data['access_token'],
                file_get_contents(base_path('.env'))
            ));

            $this->info('Zoho access token updated successfully.');
        } else {
            $this->error('Failed to refresh Zoho access token.');
        }
    }
}
