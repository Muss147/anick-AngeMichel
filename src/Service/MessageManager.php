<?php
// src/Service/MessageManager.php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class MessageManager
{
    private string $googleScriptUrl;

    public function __construct(
        private HttpClientInterface $client,
        string $googleScriptUrl
    ) {
        $this->googleScriptUrl = $googleScriptUrl;
    }

    public function sendMessageToSheet(array $data): bool
    {
        try {
            $this->client->request('POST', $this->googleScriptUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $data,
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function getMessagesFromSheet(): array
    {
        try {
            $response = $this->client->request('GET', $this->googleScriptUrl);
            $data = $response->toArray(); // Transforme le JSON en tableau PHP associatif
            return $data;
        } catch (\Exception $e) {
            return [];
        }
    }
}