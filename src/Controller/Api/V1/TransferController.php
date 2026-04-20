<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\DTO\TransferRequest;
use App\Exception\AccountNotFoundException;
use App\Exception\AccountNotActiveException;
use App\Exception\CurrencyMismatchException;
use App\Exception\DuplicateTransferException;
use App\Exception\InsufficientFundsException;
use App\Exception\SelfTransferException;
use App\Service\TransferService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1', name: 'api_v1_')]
class TransferController extends AbstractController
{
    public function __construct(
        private readonly TransferService     $transferService,
        private readonly ValidatorInterface  $validator,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface     $logger,
    ) {}

    #[Route('/transfers', name: 'transfer_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $dto = $this->serializer->deserialize(
                $request->getContent(),
                TransferRequest::class,
                'json'
            );
        } catch (\Throwable) {
            return $this->json(
                ['code' => 'INVALID_JSON', 'message' => 'Request body is not valid JSON.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[$v->getPropertyPath()] = $v->getMessage();
            }
            return $this->json(
                ['code' => 'VALIDATION_ERROR', 'errors' => $errors],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $result = $this->transferService->execute(
                $dto,
                $this->getUser(),
                $request->getClientIp()
            );
            return $this->json($result, Response::HTTP_CREATED);

        } catch (DuplicateTransferException $e) {
            return $this->json(['code' => 'DUPLICATE_REQUEST',   'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (AccountNotFoundException $e) {
            return $this->json(['code' => 'ACCOUNT_NOT_FOUND',   'message' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (AccountNotActiveException $e) {
            return $this->json(['code' => 'ACCOUNT_NOT_ACTIVE',  'message' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (InsufficientFundsException $e) {
            return $this->json(['code' => 'INSUFFICIENT_FUNDS',  'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (CurrencyMismatchException $e) {
            return $this->json(['code' => 'CURRENCY_MISMATCH',   'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SelfTransferException $e) {
            return $this->json(['code' => 'SELF_TRANSFER',       'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            return $this->json(['code' => 'SERVICE_UNAVAILABLE', 'message' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('transfer.unexpected_error', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            return $this->json(
                ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}