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

    #[Route('', name: 'api_invite_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $invites = $this->inviteManager->getAllInvites();
        return $this->json($invites);
    }

    #[Route('/entrants', name: 'api_invite_entrants', methods: ['GET'])]
    public function entrants(): JsonResponse
    {
        $invites = $this->inviteManager->getEnteredInvites();
        return $this->json($invites);
    }

    #[Route('', name: 'api_invite_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $invite = $this->inviteManager->addInvite($data);
        return $this->json($invite, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_invite_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $invite = $this->inviteManager->updateInvite($id, $data);
        return $this->json($invite);
    }

    #[Route('/{id}', name: 'api_invite_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $this->inviteManager->deleteInvite($id);
        return $this->json(['message' => 'Invité supprimé.']);
    }

    #[Route('/scan/{code}', name: 'api_invite_scan', methods: ['GET'])]
    public function findByQRCode(string $code): JsonResponse
    {
        $invite = $this->inviteManager->getInviteByQrCode($code);
        if (!$invite) {
            return $this->json(['message' => 'Invité non trouvé'], 404);
        }
        return $this->json($invite);
    }
}
