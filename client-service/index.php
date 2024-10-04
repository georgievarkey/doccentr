<?php
// client-service/index.php
require 'vendor/autoload.php';

use Aws\Ssm\SsmClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$app = new \Slim\App();

// Set up logging
$log = new Logger('client-service');
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

$app->get('/clients', function ($request, $response) use ($log) {
    try {
        $stmt = $this->db->query("SELECT * FROM clients");
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $response->withJson($clients);
    } catch (\PDOException $e) {
        $log->error('Database error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'Failed to retrieve clients']);
    } catch (\Exception $e) {
        $log->error('Unexpected error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'An unexpected error occurred']);
    }
});

$app->post('/clients', function ($request, $response) use ($log) {
    $data = $request->getParsedBody();

    try {
        $this->db->beginTransaction();

        $stmt = $this->db->prepare("INSERT INTO clients (name, email, address, phone) VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['name'], $data['email'], $data['address'], $data['phone']]);
        $clientId = $this->db->lastInsertId();

        $this->db->commit();

        return $response->withJson(['id' => $clientId], 201);
    } catch (\PDOException $e) {
        $this->db->rollBack();
        $log->error('Database error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'Failed to create client']);
    } catch (\Exception $e) {
        $this->db->rollBack();
        $log->error('Unexpected error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'An unexpected error occurred']);
    }
});

$app->get('/clients/{id}', function ($request, $response, $args) use ($log) {
    try {
        $stmt = $this->db->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$args['id']]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            return $response->withStatus(404)->withJson(['error' => 'Client not found']);
        }

        return $response->withJson($client);
    } catch (\PDOException $e) {
        $log->error('Database error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'Failed to retrieve client']);
    } catch (\Exception $e) {
        $log->error('Unexpected error: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'An unexpected error occurred']);
    }
});

$app->run();
