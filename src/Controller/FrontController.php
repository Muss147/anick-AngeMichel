<?php

namespace App\Controller;

use App\Service\MessageManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class FrontController extends AbstractController
{
    public function __construct(private MessageManager $messageManager) {}

    #[Route('/', name: 'app_front', methods: ['GET'])]
    public function index(MessageManager $messageManager): Response
    {
        $messages = $messageManager->getMessagesFromSheet();

        return $this->render('front.html.twig', [
            'messages' => $messages
        ]);
    }

    #[Route('/post-message', name: 'post_message', methods: ['POST'])]
    public function postMessage(Request $request, SessionInterface $session): Response
    {
        if ($request->isMethod('POST')) {

            if ($session->get('message_sent')) {
                $this->addFlash('info', '📝 Vous avez déjà envoyé un message. On voit que vous nous aimez vraiment, Merci pour vos encouragements.');
                return $this->redirectToRoute('app_front'); // ou n'affiche pas le formulaire
            }

            $data = [
                'name' => $request->request->get('name'),
                'lien' => $request->request->get('lien'),
                'message' => $request->request->get('message'),
            ];

            if ($this->messageManager->sendMessageToSheet($data)) {
                $session->set('message_sent', true);
                $this->addFlash('success', '🥳 Merci pour votre message !');
            } else {
                $this->addFlash('error', '😳 Une erreur est survenue.');
            }
        }
        return $this->redirectToRoute('app_front');
    }
}
