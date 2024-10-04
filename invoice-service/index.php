<?php
// invoice-service/index.php
require 'vendor/autoload.php';

use Aws\EventBridge\EventBridgeClient;
use Aws\Ssm\SsmClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$app = new \Slim\App();

// Set up logging
$log = new Logger('invoice-service');
$log->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));

// Set up database connection
$container = $app->getContainer();
$container['db'] = function ($c) use ($log) {
    $ssmClient = new SsmClient([
        'version' => 'latest',
        'region'  => getenv('AWS_REGION')
    ]);

    try {
        $result = $ssmClient->getParameters([
            'Names' => ['db_host', 'db_name', 'db_user', 'db_password'],
            'WithDecryption' => true
        ]);

        $params = [];
        foreach ($result['Parameters'] as $param) {
            $params[$param['Name']] = $param['Value'];
        }

        $db = new PDO(
            "mysql:host={$params['db_host']};dbname={$params['db_name']}",
            $params['db_user'],
            $params['db_password']
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    } catch (\Exception $e) {
        $log->error('Failed to connect to database: ' . $e->getMessage());
        throw new \Exception('Database connection failed');
    }
};

$container['eventBridge'] = function ($c) {
    return new EventBridgeClient([
        'version' => 'latest',
        'region'  => getenv('AWS_REGION')
    ]);
};

$app->post('/invoices', function ($request, $response) use ($log) {
    $data = $request->getParsedBody();

    try {
        $this->db->beginTransaction();

        $stmt = $this->db->prepare("INSERT INTO invoices (client_id, total_amount, status) VALUES (?, ?, ?)");
        $stmt->execute([$data['client_id'], $data['total_amount'], 'unpaid']);
        $invoiceId = $this->db->lastInsertId();

        // Insert invoice items
        $itemStmt = $this->db->prepare("INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
        foreach ($data['items'] as $item) {
            $itemStmt->execute([
                $invoiceId,
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['quantity'] * $item['unit_price']
            ]);
        }

        $this->db->commit();

        // Publish event
        try {
            $this->eventBridge->putEvents([
                'Entries' => [
                    [
                        'Source' => 'com.invoicemanagement.invoice',
                        'DetailType' => 'InvoiceCreated',
                        'Detail' => json_encode([
                            'invoiceId' => $invoiceId,
                            'clientId' => $data['client_id'],
                            'amount' => $data['total_amount'],
                        ]),
                        'EventBusName' => 'InvoiceManagementEventBus',
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            $log->error('Failed to publish event: ' . $e->getMessage());
            // Note: We're not rethrowing here as the invoice was successfully created
        }

        return $response->withJson(['id' => $invoiceId], 201);
    } catch (\PDOException $e) {
        $this->db->rollBack();
        $log->error('Database error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'Failed to create invoice']);
    } catch (\Exception $e) {
        $this->db->rollBack();
        $log->error('Unexpected error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'An unexpected error occurred']);
    }
});

$app->get('/invoices/{id}', function ($request, $response, $args) use ($log) {
    try {
        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$args['id']]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            return $response->withStatus(404)->withJson(['error' => 'Invoice not found']);
        }

        // Fetch invoice items
        $itemStmt = $this->db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
        $itemStmt->execute([$args['id']]);
        $invoice['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        return $response->withJson($invoice);
    } catch (\PDOException $e) {
        $log->error('Database error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'Failed to retrieve invoice']);
    } catch (\Exception $e) {
        $log->error('Unexpected error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'An unexpected error occurred']);
    }
});

$app->put('/invoices/{id}', function ($request, $response, $args) use ($log) {
    $data = $request->getParsedBody();

    try {
        $this->db->beginTransaction();

        $stmt = $this->db->prepare("UPDATE invoices SET status = ? WHERE id = ?");
        $stmt->execute([$data['status'], $args['id']]);

        if ($stmt->rowCount() === 0) {
            $this->db->rollBack();
            return $response->withStatus(404)->withJson(['error' => 'Invoice not found']);
        }

        $this->db->commit();

        // Publish event
        try {
            $this->eventBridge->putEvents([
                'Entries' => [
                    [
                        'Source' => 'com.invoicemanagement.invoice',
                        'DetailType' => 'InvoiceUpdated',
                        'Detail' => json_encode([
                            'invoiceId' => $args['id'],
                            'status' => $data['status'],
                        ]),
                        'EventBusName' => 'InvoiceManagementEventBus',
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            $log->error('Failed to publish event: ' . $e->getMessage());
            // Note: We're not rethrowing here as the invoice was successfully updated
        }

        return $response->withJson(['success' => true]);
    } catch (\PDOException $e) {
        $this->db->rollBack();
        $log->error('Database error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'Failed to update invoice']);
    } catch (\Exception $e) {
        $this->db->rollBack();
        $log->error('Unexpected error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'An unexpected error occurred']);
    }
});

$app->run();
