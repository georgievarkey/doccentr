<?php
// order-service/index.php
require 'vendor/autoload.php';

use Aws\Ssm\SsmClient;
use Aws\EventBridge\EventBridgeClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$app = new \Slim\App();

// Set up logging
$log = new Logger('order-service');
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

$app->post('/orders', function ($request, $response) use ($log) {
    $data = $request->getParsedBody();

    try {
        $this->db->beginTransaction();

        $stmt = $this->db->prepare("INSERT INTO orders (client_id, total_amount, status) VALUES (?, ?, ?)");
        $stmt->execute([$data['client_id'], $data['total_amount'], 'pending']);
        $orderId = $this->db->lastInsertId();

        $itemStmt = $this->db->prepare("INSERT INTO order_items (order_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
        foreach ($data['items'] as $item) {
            $itemStmt->execute([
                $orderId,
                $item['product_name'],
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
                        'Source' => 'com.invoicemanagement.order',
                        'DetailType' => 'OrderCreated',
                        'Detail' => json_encode([
                            'orderId' => $orderId,
                            'clientId' => $data['client_id'],
                            'amount' => $data['total_amount'],
                        ]),
                        'EventBusName' => 'InvoiceManagementEventBus',
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            $log->error('Failed to publish event: ' . $e->getMessage());
            // Note: We're not rethrowing here as the order was successfully created
        }

        return $response->withJson(['id' => $orderId], 201);
    } catch (\PDOException $e) {
        $this->db->rollBack();
        $log->error('Database error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'Failed to create order']);
    } catch (\Exception $e) {
        $this->db->rollBack();
        $log->error('Unexpected error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'An unexpected error occurred']);
    }
});

$app->get('/orders/{id}', function ($request, $response, $args) use ($log) {
    try {
        $stmt = $this->db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$args['id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return $response->withStatus(404)->withJson(['error' => 'Order not found']);
        }

        $itemStmt = $this->db->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $itemStmt->execute([$args['id']]);
        $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        return $response->withJson($order);
    } catch (\PDOException $e) {
        $log->error('Database error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'Failed to retrieve order']);
    } catch (\Exception $e) {
        $log->error('Unexpected error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'An unexpected error occurred']);
    }
});

$app->run();
