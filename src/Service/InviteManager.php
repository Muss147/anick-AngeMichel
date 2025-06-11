<?php
// src/Service/InviteManager.php

namespace App\Service;

use Google_Client;
use Google_Service_Sheets;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\Filesystem\Filesystem;

class InviteManager
{
    private $sheets;
    private $spreadsheetId;
    private $filesystem;
    private $sheetName = 'Invites'; // Nom de l'onglet

    public function __construct(string $googleSheetId)
    {
        $client = new Google_Client();
        $client->setApplicationName('Symfony Invite Manager');
        $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $client->setAuthConfig(__DIR__ . '/../../config/google-credentials.json');
        $this->sheets = new Google_Service_Sheets($client);
        $this->spreadsheetId = $googleSheetId;
        $this->filesystem = new Filesystem();
    }

    public function addInvite(array $inviteData): void
    {
        $uniqueId = uniqid('invite_', true);
        $qrPath = $this->generateQrImage($uniqueId);
        $finalImagePath = $this->generateInvitationImage($qrPath, $uniqueId);

        $row = [
            $inviteData['Name'],
            $inviteData['CountryCode'],
            $inviteData['Phone'],
            'TRUE',
            'TRUE',
            $finalImagePath,
            $inviteData['num_table'] ?? '',
            'FALSE',
            date('Y-m-d'),
            ''
        ];

        $this->sheets->spreadsheets_values->append(
            $this->spreadsheetId,
            $this->sheetName . '!A1',
            new \Google_Service_Sheets_ValueRange(['values' => [$row]]),
            ['valueInputOption' => 'USER_ENTERED']
        );
    }

    public function updateInvite(int $rowIndex, array $inviteData): void
    {
        $rows = $this->getAllInvites();
        $oldImagePath = $rows[$rowIndex][5] ?? null;

        if ($oldImagePath && $this->filesystem->exists($oldImagePath)) {
            $this->filesystem->remove($oldImagePath);
        }

        $uniqueId = uniqid('invite_', true);
        $qrPath = $this->generateQrImage($uniqueId);
        $finalImagePath = $this->generateInvitationImage($qrPath, $uniqueId);

        $row = [
            $inviteData['Name'],
            $inviteData['CountryCode'],
            $inviteData['Phone'],
            'TRUE',
            'TRUE',
            $finalImagePath,
            $inviteData['num_table'] ?? '',
            $inviteData['estEntre'] ?? 'FALSE',
            date('Y-m-d'),
            $inviteData['heure_entree'] ?? ''
        ];

        $this->sheets->spreadsheets_values->update(
            $this->spreadsheetId,
            $this->sheetName . '!A' . ($rowIndex + 1),
            new \Google_Service_Sheets_ValueRange(['values' => [$row]]),
            ['valueInputOption' => 'USER_ENTERED']
        );
    }

    public function deleteInvite(int $rowIndex): void
    {
        $rows = $this->getAllInvites();
        $imagePath = $rows[$rowIndex][5] ?? null;

        if ($imagePath && $this->filesystem->exists($imagePath)) {
            $this->filesystem->remove($imagePath);
        }

        $requestBody = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
            'requests' => [[
                'deleteDimension' => [
                    'range' => [
                        'sheetId' => 0, // souvent 0 pour la 1re feuille
                        'dimension' => 'ROWS',
                        'startIndex' => $rowIndex,
                        'endIndex' => $rowIndex + 1
                    ]
                ]
            ]]
        ]);

        $this->sheets->spreadsheets->batchUpdate($this->spreadsheetId, $requestBody);
    }

    public function getAllInvites(): array
    {
        $response = $this->sheets->spreadsheets_values->get(
            $this->spreadsheetId,
            $this->sheetName . '!A2:J'
        );
        return $response->getValues() ?? [];
    }

    public function getCheckedInInvites(): array
    {
        return array_filter($this->getAllInvites(), function ($row) {
            return isset($row[7]) && $row[7] === 'TRUE';
        });
    }

    private function generateQrImage(string $uniqueId): string
    {
        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($uniqueId)
            ->size(300)
            ->margin(10)
            ->foregroundColor(new Color(66, 87, 67))
            ->backgroundColor(new Color(255, 255, 255))
            ->build();

        $qrPath = __DIR__ . "/../../public/assets/docs/qrcodes/{$uniqueId}.png";
        $result->saveToFile($qrPath);

        return $qrPath;
    }

    private function generateInvitationImage(string $qrPath, string $uniqueId): string
    {
        $baseImage = imagecreatefrompng(__DIR__ . '/../../public/assets/docs/modele_invitation.png');
        $qrImage = imagecreatefrompng($qrPath);

        $baseWidth = imagesx($baseImage);
        $qrWidth = imagesx($qrImage);
        $x = ($baseWidth - $qrWidth) / 2;
        $y = imagesy($baseImage) - $qrWidth - 300;

        imagecopy($baseImage, $qrImage, $x, $y, 0, 0, $qrWidth, $qrWidth);

        $finalPath = __DIR__ . "/../../public/assets/docs/invitations/{$uniqueId}.png";
        imagepng($baseImage, $finalPath);
        imagedestroy($baseImage);
        imagedestroy($qrImage);

        return "/assets/docs/invitations/{$uniqueId}.png";
    }
}
