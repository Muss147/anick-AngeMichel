<?php

namespace App\Controller;

use App\Service\InviteManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;

#[Route('/api/invites')]
class InviteController extends AbstractController
{
    public function __construct(private InviteManager $inviteManager) {}

    #[Route('/generate-all-colomns', name: 'generateAllColomns', methods: ['GET'])]
    public function generateAllColomns(): JsonResponse
    {
        $invites = $this->inviteManager->generateImagesForExistingInvites();
        return $this->json($invites);
    }

    #[Route('', name: 'invite_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $invites = $this->inviteManager->listInvites();
        return $this->json($invites);
    }

    #[Route('/entres', name: 'invite_list_entres', methods: ['GET'])]
    public function listEntres(): JsonResponse
    {
        $invites = $this->inviteManager->listCheckedInInvites();
        return $this->json($invites);
    }

    #[Route('', name: 'invite_add', methods: ['POST'])]
    public function add(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['Name'], $data['CountryCode'], $data['Phone'], $data['num_table'])) {
            return $this->json(['error' => 'Missing required fields.'], Response::HTTP_BAD_REQUEST);
        }

        $invite = $this->inviteManager->addInvite($data);
        return $this->json($invite, Response::HTTP_CREATED);
    }

    #[Route('/{rowIndex}', name: 'invite_update', methods: ['PUT'])]
    public function update(int $rowIndex, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid data.'], Response::HTTP_BAD_REQUEST);
        }

        $invite = $this->inviteManager->updateInvite($rowIndex, $data);
        return $this->json($invite);
    }

    #[Route('/{rowIndex}', name: 'invite_delete', methods: ['DELETE'])]
    public function delete(int $rowIndex): JsonResponse
    {
        $this->inviteManager->deleteInvite($rowIndex);
        return $this->json(['message' => 'Invite deleted successfully.']);
    }

    #[Route('/scan/{id}', name: 'invite_by_qr', methods: ['GET'])]
    public function getByQrId(string $id): Response
    {
        // Vérifie si l'utilisateur est connecté
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_front'); // Nom de ta route d’accueil
        }

        $invite = $this->inviteManager->findInviteByQr($id);
        if (!$invite) {
            return $this->json(['error' => 'Invite not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($invite);
    }
}
