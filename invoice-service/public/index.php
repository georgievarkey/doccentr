<?php
// public/index.php
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container();
AppFactory::setContainer($container);

$app = AppFactory::create();

// Add error middleware
$app->addErrorMiddleware(true, true, true);

$app->addBodyParsingMiddleware();

// Add Monolog
$container->set(LoggerInterface::class, function() {
    $logger = new Logger('app');
    $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));
    return $logger;
});

// Load configurations
$container->set('config', function() {
    return require __DIR__ . '/../src/config.php';
});

// Database connection
$container->set('db', function($c) {
    $config = $c->get('config')['db'];
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
    return new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
});

// Load routes
(require __DIR__ . '/../src/routes.php')($app);

// Add 404 middleware
$app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function ($request, $response) {
    throw new HttpNotFoundException($request);
});

$app->run();
