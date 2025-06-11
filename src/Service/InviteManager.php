<?php

namespace App\Service;

use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use Symfony\Component\Filesystem\Filesystem;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Imagick;

class InviteManager
{
    private Google_Service_Sheets $service;
    private string $sheetId;
    private string $sheetName;
    private string $imageDirectory;
    private Filesystem $filesystem;

    public function __construct(
    string $googleSheetId,
    string $sheetName,
    string $projectDir
    ) {
        $this->sheetId = $googleSheetId;
        $this->sheetName = $sheetName;
        $this->imageDirectory = $projectDir . '/public/assets/docs';
        $this->filesystem = new Filesystem();

        $credentialsPath = $projectDir . '/config/credentials/google-sheet-credentials.json';

        if (!file_exists($credentialsPath)) {
            throw new \RuntimeException("Fichier de credentials Google Sheets introuvable à : " . $credentialsPath);
        }

        $this->service = $this->createGoogleSheetsService($credentialsPath);
    }

    // Création du client Google
    private function createGoogleSheetsService(string $credentialsPath): Google_Service_Sheets
    {
        $client = new Google_Client();
        $client->setAuthConfig($credentialsPath);
        $client->addScope(Google_Service_Sheets::SPREADSHEETS);
        return new Google_Service_Sheets($client);
    }

    public function addInvite(array $inviteData): void
    {
        $uniqueId = uniqid('invite_', true);
        $qrPath = $this->generateQrCode($uniqueId);
        $finalImagePath = $this->mergeQrCodeWithImage($qrPath, $uniqueId);

        $row = [
            $inviteData['name'],
            $inviteData['countryCode'],
            $inviteData['phone'],
            'TRUE',
            'TRUE',
            $finalImagePath,
            $inviteData['num_table'],
            'FALSE',
            date('Y-m-d'),
            '',
            $uniqueId
        ];

        $body = new Google_Service_Sheets_ValueRange([
            'values' => [$row]
        ]);

        $params = ['valueInputOption' => 'RAW'];
        $this->service->spreadsheets_values->append(
            $this->sheetId,
            $this->sheetName,
            $body,
            $params
        );
    }

    public function updateInvite(string $uniqueId, array $newData): void
    {
        $rows = $this->getAllRows();
        foreach ($rows as $index => $row) {
            if (isset($row[10]) && $row[10] === $uniqueId) {
                $oldImage = $row[5] ?? null;
                if ($oldImage) {
                    $this->filesystem->remove($this->imageDirectory . '/' . basename($oldImage));
                }

                $qrPath = $this->generateQrCode($uniqueId);
                $finalImagePath = $this->mergeQrCodeWithImage($qrPath, $uniqueId);

                $updatedRow = [
                    $newData['name'],
                    $newData['countryCode'],
                    $newData['phone'],
                    'TRUE',
                    'TRUE',
                    $finalImagePath,
                    $newData['num_table'],
                    $row[7] ?? 'FALSE',
                    $row[8] ?? date('Y-m-d'),
                    $row[9] ?? '',
                    $uniqueId
                ];

                $body = new Google_Service_Sheets_ValueRange([
                    'values' => [$updatedRow]
                ]);
                $range = $this->sheetName . '!A' . ($index + 1);
                $this->service->spreadsheets_values->update(
                    $this->sheetId,
                    $range,
                    $body,
                    ['valueInputOption' => 'RAW']
                );
                break;
            }
        }
    }

    public function deleteInvite(string $uniqueId): void
    {
        $rows = $this->getAllRows();
        foreach ($rows as $index => $row) {
            if (isset($row[10]) && $row[10] === $uniqueId) {
                if (isset($row[5])) {
                    $this->filesystem->remove($this->imageDirectory . '/' . basename($row[5]));
                }

                $requests = [[
                    'deleteDimension' => [
                        'range' => [
                            'sheetId' => 0,
                            'dimension' => 'ROWS',
                            'startIndex' => $index,
                            'endIndex' => $index + 1
                        ]
                    ]
                ]];
                $batchUpdateRequest = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                    'requests' => $requests
                ]);

                $this->service->spreadsheets->batchUpdate($this->sheetId, $batchUpdateRequest);
                break;
            }
        }
    }

    public function listInvites(): array
    {
        return $this->getAllRows();
    }

    public function listCheckedInInvites(): array
    {
        return array_filter($this->getAllRows(), fn($row) => isset($row[7]) && $row[7] === 'TRUE');
    }

    public function findInviteByQr(string $uniqueId): ?array
    {
        foreach ($this->getAllRows() as $row) {
            if (isset($row[10]) && $row[10] === $uniqueId) {
                return $row;
            }
        }
        return null;
    }

    private function getAllRows(): array
    {
        $response = $this->service->spreadsheets_values->get($this->sheetId, $this->sheetName);
        return $response->getValues() ?? [];
    }

    private function generateQrCode(string $data): string
    {
        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->foregroundColor(66, 87, 67)
            ->backgroundColor(255, 255, 255)
            ->build();

        $qrPath = $this->imageDirectory . '/' . $data . '_qr.png';
        $result->saveToFile($qrPath);

        return $qrPath;
    }

    private function mergeQrCodeWithImage(string $qrPath, string $identifier): string
    {
        $baseImagePath = $this->imageDirectory . '/modele_invitation.png';
        $outputImagePath = $this->imageDirectory . '/' . $identifier . '_final.png';

        $base = new Imagick($baseImagePath);
        $qr = new Imagick($qrPath);

        $baseWidth = $base->getImageWidth();
        $qrWidth = $qr->getImageWidth();

        $x = (int)(($baseWidth - $qrWidth) / 2);
        $y = $base->getImageHeight() - $qrWidth - 300;

        $base->compositeImage($qr, Imagick::COMPOSITE_OVER, $x, $y);
        $base->writeImage($outputImagePath);

        $qr->clear();
        $base->clear();

        return 'assets/docs/' . basename($outputImagePath);
    }
    
    public function generateImagesForExistingInvites(): void
    {
        $rows = $this->getAllRows(); // Lit toutes les lignes de la feuille
        foreach ($rows as $index => $row) {
            // Ignore la première ligne si c'est l'en-tête
            if ($index === 0) {
                continue;
            }

            // Récupère ou crée l'ID unique
            $uniqueId = $row[10] ?? uniqid('invite_', true);

            // Génére le QR code et fusionne avec l’image
            $qrPath = $this->generateQrCode($uniqueId);
            $finalImagePath = $this->mergeQrCodeWithImage($qrPath, $uniqueId);

            // Met à jour la cellule dans la colonne F (colonne 5 = index 5)
            $updatedRow = $row;
            $updatedRow[5] = $finalImagePath;
            $updatedRow[10] = $uniqueId;

            // Mets à jour la ligne dans la feuille
            $range = $this->sheetName . '!A' . ($index + 1); // +1 car l’index commence à 0
            $body = new \Google_Service_Sheets_ValueRange([
                'values' => [$updatedRow]
            ]);
            $this->service->spreadsheets_values->update(
                $this->sheetId,
                $range,
                $body,
                ['valueInputOption' => 'RAW']
            );
        }
    }
}