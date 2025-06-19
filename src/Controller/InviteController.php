<?php

namespace App\Controller;

use App\Service\InviteManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

#[Route('/api/invites')]
class InviteController extends AbstractController
{
    public function __construct(private InviteManager $inviteManager) {}

    #[Route('', name: 'invite_dash', methods: ['GET'])]
    public function dash(SessionInterface $session): Response
    {
        $session->set('menu', 'dash');
        return $this->render('backend/dashboard.html.twig');
    }

    #[Route('admin', name: 'info_admin', methods: ['GET', 'POST'])]
    public function infoAdmin(SessionInterface $session): Response
    {
        $session->set('menu', 'admin');
        return $this->render('backend/admin.html.twig');
    }

    #[Route('/liste', name: 'invite_list', methods: ['GET'])]
    public function list(SessionInterface $session): Response
    {
        $session->set('menu', 'liste');
        $invites = $this->inviteManager->listInvites();
        return $this->render('backend/invites.html.twig', [
            'invites' => $invites
        ]);
    }

    #[Route('/liste/entres', name: 'invite_list_entres', methods: ['GET'])]
    public function listEntres(): JsonResponse
    {
        $invites = $this->inviteManager->listCheckedInInvites();
        return $this->json($invites);
    }

    #[Route('/liste/ajout', name: 'invite_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $data['name'] = $request->get('user_name');
            $data['countryCode'] = $request->get('user_indicator');
            $data['phone'] = $request->get('user_tel');
            $data['num_table'] = $request->get('user_table');

            $invite = $this->inviteManager->addInvite($data);
            $this->addFlash('success', "L'invité <b>". $invite ."</b> a été ajouté avec succès.");
        }
        return $this->redirectToRoute('invite_list');
    }

    #[Route('/liste/modification', name: 'invite_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $rowIndex = $request->get('user_id') ?? null;
        if ($request->isMethod('POST') && $rowIndex) {
            $data['name'] = $request->get('user_name');
            $data['countryCode'] = $request->get('user_indicator');
            $data['phone'] = $request->get('user_tel');
            $data['num_table'] = $request->get('user_table');

            $invite = $this->inviteManager->updateInvite($rowIndex, $data);
            $this->addFlash('success', 'Modification effectuée avec succès.');
        }
        return $this->redirectToRoute('invite_list');
    }

    #[Route('/liste/delete/{rowIndex}', name: 'invite_delete', methods: ['POST'])]
    public function delete(string $rowIndex, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete'.$rowIndex, $request->getPayload()->getString('_token'))) {
            $this->inviteManager->deleteInvite($rowIndex);
        }
        return $this->redirectToRoute('invite_list');
    }

    #[Route('/delete-users-selected', name: 'users_selected_delete', methods: ['POST'])]
    public function deleteUsersSelected(Request $request, EntityManagerInterface $em, UsersRepository $usersRepository): Response
    {
        // Récupérer les données JSON de la requête
        $data = json_decode($request->getContent(), true);

        if ($request->isXmlHttpRequest()) {
            foreach ($data['usersDeleted'] as $id) {
                if ($user = $usersRepository->find($id)) $user->remove($this->getUser());
                $em->flush();
            }
        }
        return new JsonResponse(true, 200);
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

    #[Route('/generate-all-colomns', name: 'generateAllColomns', methods: ['GET'])]
    public function generateAllColomns(): JsonResponse
    {
        $invites = $this->inviteManager->generateImagesForExistingInvites();
        return $this->json($invites);
    }
}
