<?php

namespace App\Controller\Webhook;

use App\Entity\Wallet\Cheque;
use App\Service\WalletAuditService;
use App\Service\YousignService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives Yousign webhook events (public endpoint, no auth).
 * Configure this URL in the Yousign dashboard: {APP_URL}/webhook/yousign
 */
#[Route('/webhook/yousign', name: 'webhook_yousign', methods: ['POST'])]
class YousignWebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly YousignService $yousignService,
        private readonly WalletAuditService $walletAuditService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signatureHeader = $request->headers->get('X-Yousign-Signature-256', '');

        if (!$this->yousignService->verifyWebhookSignature($rawPayload, $signatureHeader)) {
            $this->logger->warning('Yousign webhook: signature invalide', [
                'ip' => $request->getClientIp(),
            ]);
            return new JsonResponse(['error' => 'Invalid signature'], 401);
        }

        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        $eventName = (string) ($payload['event_name'] ?? '');
        $procedureId = $payload['data']['signature_request']['id']
            ?? $payload['data']['signing_request']['id']
            ?? $payload['data']['signer']['signature_request']['id']
            ?? $payload['data']['signer']['signature_request_id']
            ?? $payload['data']['id']
            ?? null;

        $this->logger->info('Yousign webhook received', [
            'event' => $eventName,
            'procedure_id' => $procedureId,
        ]);

        if ($procedureId === null) {
            return new JsonResponse(['status' => 'ignored', 'reason' => 'no signature_request id']);
        }

        match ($eventName) {
            'signature_request.activated' => $this->handleSigningActivated($procedureId),
            'signature_request.done',
            'signing_request.done',
            'signer.done' => $this->handleSigningDone($procedureId),
            'signature_request.declined',
            'signature_request.expired',
            'signature_request.canceled',
            'signature_request.email_notification.delivery_failed',
            'signer.declined',
            'signing_request.expired',
            'signing_request.canceled' => $this->handleSigningFailed($procedureId, $eventName),
            default => null,
        };

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleSigningActivated(string $procedureId): void
    {
        $cheque = $this->findChequeByProcedureId($procedureId);
        if (!$cheque) {
            $this->logger->warning('Yousign webhook: cheque not found', ['procedure_id' => $procedureId]);
            return;
        }

        if ($cheque->getYousignStatus() === 'signed') {
            return;
        }

        $cheque->setYousignStatus('pending');
        $cheque->setYousignSignedAt(null);
        $this->entityManager->flush();

        $this->walletAuditService->logChequeAction(
            'wallet.cheque.yousign_activated',
            $cheque->getIdCheque(),
            $cheque->getWallet()->getIdWallet(),
            null,
            'yousign_pending'
        );

        $this->logger->info('Yousign: signing request activated', ['cheque_id' => $cheque->getIdCheque()]);
    }

    private function handleSigningDone(string $procedureId): void
    {
        $cheque = $this->findChequeByProcedureId($procedureId);
        if (!$cheque) {
            $this->logger->warning('Yousign webhook: cheque not found', ['procedure_id' => $procedureId]);
            return;
        }

        $cheque->setYousignStatus('signed');
        $cheque->setYousignSignedAt(new \DateTime());
        $this->entityManager->flush();

        $this->walletAuditService->logChequeAction(
            'wallet.cheque.yousign_signed',
            $cheque->getIdCheque(),
            $cheque->getWallet()->getIdWallet(),
            null,
            'yousign_signed'
        );

        $this->logger->info('Yousign: cheque signed', ['cheque_id' => $cheque->getIdCheque()]);
    }

    private function handleSigningFailed(string $procedureId, string $eventName): void
    {
        $cheque = $this->findChequeByProcedureId($procedureId);
        if (!$cheque) {
            return;
        }

        $status = str_contains($eventName, 'expired')
            ? 'expired'
            : (str_contains($eventName, 'declined') || str_contains($eventName, 'canceled') ? 'refused' : 'failed');

        $cheque->setYousignStatus($status);
        $this->entityManager->flush();

        $this->walletAuditService->logChequeAction(
            'wallet.cheque.yousign_failed',
            $cheque->getIdCheque(),
            $cheque->getWallet()->getIdWallet(),
            null,
            'yousign_' . $status
        );

        $this->logger->warning('Yousign: signing failed/expired', [
            'cheque_id' => $cheque->getIdCheque(),
            'event' => $eventName,
        ]);
    }

    private function findChequeByProcedureId(string $procedureId): ?Cheque
    {
        /** @var Cheque|null $cheque */
        $cheque = $this->entityManager->getRepository(Cheque::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.wallet', 'w')
            ->addSelect('w')
            ->andWhere('c.yousignProcedureId = :pid')
            ->setParameter('pid', $procedureId)
            ->getQuery()
            ->getOneOrNullResult();

        return $cheque;
    }
}
