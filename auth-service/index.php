<?php
// auth-service/index.php
require 'vendor/autoload.php';

use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\Ssm\SsmClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$app = new \Slim\App();

// Set up logging
$log = new Logger('auth-service');
$log->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));

// Set up Cognito client
$container = $app->getContainer();
$container['cognito'] = function ($c) use ($log) {
    $ssmClient = new SsmClient([
        'version' => 'latest',
        'region'  => getenv('AWS_REGION')
    ]);

    try {
        $result = $ssmClient->getParameters([
            'Names' => ['cognito_user_pool_id', 'cognito_client_id'],
            'WithDecryption' => true
        ]);

        $params = [];
        foreach ($result['Parameters'] as $param) {
            $params[$param['Name']] = $param['Value'];
        }

        return new CognitoIdentityProviderClient([
            'version' => 'latest',
            'region'  => getenv('AWS_REGION'),
            'credentials' => [
                'key'    => getenv('AWS_ACCESS_KEY_ID'),
                'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
            ],
        ]);
    } catch (\Exception $e) {
        $log->error('Failed to initialize Cognito client: ' . $e->getMessage());
        throw new \Exception('Cognito client initialization failed');
    }
};

$app->post('/register', function ($request, $response) use ($log) {
    $data = $request->getParsedBody();

    try {
        $result = $this->cognito->signUp([
            'ClientId' => getenv('COGNITO_CLIENT_ID'),
            'Username' => $data['email'],
            'Password' => $data['password'],
            'UserAttributes' => [
                [
                    'Name' => 'email',
                    'Value' => $data['email']
                ]
            ],
        ]);

        return $response->withJson(['success' => true, 'message' => 'User registered successfully']);
    } catch (\Exception $e) {
        $log->error('Registration failed: ' . $e->getMessage());
        return $response->withStatus(400)->withJson(['success' => false, 'message' => $e->getMessage()]);
    }
});

$app->post('/login', function ($request, $response) use ($log) {
    $data = $request->getParsedBody();

    try {
        $result = $this->cognito->initiateAuth([
            'AuthFlow' => 'USER_PASSWORD_AUTH',
            'ClientId' => getenv('COGNITO_CLIENT_ID'),
            'AuthParameters' => [
                'USERNAME' => $data['email'],
                'PASSWORD' => $data['password']
            ],
        ]);

        return $response->withJson([
            'success' => true, 
            'token' => $result['AuthenticationResult']['IdToken']
        ]);
    } catch (\Exception $e) {
        $log->error('Login failed: ' . $e->getMessage());
        return $response->withStatus(401)->withJson(['success' => false, 'message' => 'Authentication failed']);
    }
});

$app->run();
