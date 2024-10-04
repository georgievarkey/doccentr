<?php
// src/Controllers/InvoiceController.php
namespace App\Controllers;

use App\Services\InvoiceService;
use App\Exceptions\InvoiceException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class InvoiceController
{
    private $invoiceService;
    private $logger;

    public function __construct(InvoiceService $invoiceService, LoggerInterface $logger)
    {
        $this->invoiceService = $invoiceService;
        $this->logger = $logger;
    }

    public function getAll(Request $request, Response $response): Response
    {
        try {
            $invoices = $this->invoiceService->getAllInvoices();
            $response->getBody()->write(json_encode($invoices));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $this->logger->error('Failed to get all invoices: ' . $e->getMessage());
            return $this->handleException($response, $e);
        }
    }

    public function getOne(Request $request, Response $response, array $args): Response
    {
        try {
            $invoice = $this->invoiceService->getInvoiceById($args['id']);
            if (!$invoice) {
                throw new InvoiceException("Invoice not found", 404);
            }
            $response->getBody()->write(json_encode($invoice));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (InvoiceException $e) {
            return $this->handleException($response, $e);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get invoice: ' . $e->getMessage());
            return $this->handleException($response, $e);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $this->validateInvoiceData($data);
            $invoice = $this->invoiceService->createInvoice($data);
            $response->getBody()->write(json_encode($invoice));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } catch (InvoiceException $e) {
            return $this->handleException($response, $e);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create invoice: ' . $e->getMessage());
            return $this->handleException($response, $e);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $data = $request->getParsedBody();
            $this->validateInvoiceData($data);
            $invoice = $this->invoiceService->updateInvoice($args['id'], $data);
            if (!$invoice) {
                throw new InvoiceException("Invoice not found", 404);
            }
            $response->getBody()->write(json_encode($invoice));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (InvoiceException $e) {
            return $this->handleException($response, $e);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update invoice: ' . $e->getMessage());
            return $this->handleException($response, $e);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $result = $this->invoiceService->deleteInvoice($args['id']);
            if (!$result) {
                throw new InvoiceException("Invoice not found", 404);
            }
            return $response->withStatus(204);
        } catch (InvoiceException $e) {
            return $this->handleException($response, $e);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete invoice: ' . $e->getMessage());
            return $this->handleException($response, $e);
        }
    }

    private function validateInvoiceData(array $data): void
    {
        $requiredFields = ['client_id', 'amount', 'due_date'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new InvoiceException("Missing required field: {$field}", 400);
            }
        }
        if (!is_numeric($data['amount']) || $data['amount'] <= 0) {
            throw new InvoiceException("Invalid amount", 400);
        }
        if (!strtotime($data['due_date'])) {
            throw new InvoiceException("Invalid due date", 400);
        }
    }

    private function handleException(Response $response, \Exception $e): Response
    {
        $statusCode = $e instanceof InvoiceException ? $e->getCode() : 500;
        $statusCode = $statusCode ?: 500;  // Ensure we have a valid HTTP status code
        $response->getBody()->write(json_encode([
            'error' => $e->getMessage()
        ]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }
}
