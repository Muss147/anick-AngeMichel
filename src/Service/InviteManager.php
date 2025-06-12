<?php

namespace App\Service;

use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use Symfony\Component\Filesystem\Filesystem;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Imagick;
use ImagickDraw;
use ImagickPixel;

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
        $finalImagePath = $this->mergeQrCodeWithImage($qrPath, $uniqueId, $inviteData['name']);

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
                $finalImagePath = $this->mergeQrCodeWithImage($qrPath, $uniqueId, $newData['name']);

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

    private function generateQrCode(string $data): Imagick
    {
        $result = Builder::create()
            ->writer(new PngWriter()) // Utilisez PngWriter
            ->data($data)             // Assurez-vous que $data est bien votre URL
            ->size(300)
            ->margin(10)
            ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
            ->foregroundColor(new Color(66, 87, 67))
            ->backgroundColor(new Color(255, 255, 255))
            ->build();

        // On crée un objet Imagick à partir de l’image PNG
        $imageData = $result->getString(); // Donne les données binaires de l'image
        $imagick = new Imagick();
        $imagick->readImageBlob($imageData); // On charge l’image dans Imagick

        return $imagick;
    }

    private function mergeQrCodeWithImage(Imagick $qr, string $identifier, string $inviteName): string
    {
        $baseImagePath = $this->imageDirectory . '/modele_invitation.png';
        $outputImagePath = $this->imageDirectory . '/' . $identifier . '_final.png';

        $base = new Imagick($baseImagePath);

        $baseWidth = $base->getImageWidth();
        $qrWidth = $qr->getImageWidth();

        $x = (int)(($baseWidth - $qrWidth) / 2);
        $y = $base->getImageHeight() - $qrWidth - 470;

        $base->compositeImage($qr, Imagick::COMPOSITE_OVER, $x, $y);

        // Ajout du nom
        $draw = new ImagickDraw();
        $draw->setFont(__DIR__ . '/../../public/assets/fonts/PublicSans-Regular.ttf');
        $draw->setFontSize(8.5 * 4);
        $draw->setFillColor(new ImagickPixel('#425743'));

        $metrics = $base->queryFontMetrics($draw, $inviteName);
        $textWidth = $metrics['textWidth'];
        $textX = ($base->getImageWidth() - $textWidth) / 2;
        $textY = 175;

        $base->annotateImage($draw, $textX, $textY, 0, $inviteName);

        $base->writeImage($outputImagePath);

        $qr->clear();
        $base->clear();

        return 'https://anick-angemichel-loveland.com/assets/docs/' . basename($outputImagePath);
    }
    
    public function generateImagesForExistingInvites(): void
    {
        $rows = $this->getAllRows(); // Lit toutes les lignes de la feuille
        foreach ($rows as $index => $row) {
            // Ignore la première ligne si c'est l'en-tête
            if ($index === 0) {
                continue;
            }
            
            // Vérifie si la colonne F (index 5) contient déjà une image
            if (!empty($row[5])) {
                continue; // Passe à la ligne suivante
            }

            // Vérifie si la colonne C (index 2) est vide
            if (empty($row[2])) {
                continue; // Passe à la ligne suivante
            }

            // Récupère ou crée l'ID unique
            $uniqueId = $row[10] ?? uniqid('invite_', true);
            // Récupère le nom de l'invité
            $guestName = $row[0].',';

            // Génére le QR code et fusionne avec l’image
            $qrImagick = $this->generateQrCode($uniqueId);
            $finalImagePath = $this->mergeQrCodeWithImage($qrImagick, $uniqueId, $guestName);

            // Met à jour la cellule dans la colonne F (colonne 5 = index 5)
            $updatedRow = array_pad($row, 11, '');
            $updatedRow[5] = $finalImagePath;
            $updatedRow[8] = date("d/m/Y");
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