<?php
// notification-service/index.php
require 'vendor/autoload.php';

use Aws\Ses\SesClient;
use Aws\Ssm\SsmClient;
use Aws\EventBridge\EventBridgeClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$app = new \Slim\App();

// Set up logging
$log = new Logger('notification-service');
$log->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));

// Set up SES client
$container = $app->getContainer();
$container['ses'] = function ($c) use ($log) {
    return new SesClient([
        'version' => 'latest',
        'region'  => getenv('AWS_REGION')
    ]);
};

$container['eventBridge'] = function ($c) {
    return new EventBridgeClient([
        'version' => 'latest',
        'region'  => getenv('AWS_REGION')
    ]);
};

$app->post('/send-email', function ($request, $response) use ($log) {
    $data = $request->getParsedBody();
    $invoiceId = $data['invoiceId'];
    $pdfUrl = $data['pdfUrl'];
    $recipientEmail = $data['recipientEmail'];

    try {
        // Retrieve sender email from Parameter Store
        $ssmClient = new SsmClient([
            'version' => 'latest',
            'region'  => getenv('AWS_REGION')
        ]);

        try {
            $result = $ssmClient->getParameter([
                'Name' => 'sender_email',
                'WithDecryption' => true
            ]);
            $senderEmail = $result['Parameter']['Value'];
        } catch (\Exception $e) {
            $log->error('Failed to retrieve sender email: ' . $e->getMessage());
            throw new \Exception('Failed to retrieve sender email');
        }

        // Send email using SES
        $result = $this->ses->sendEmail([
            'Source' => $senderEmail,
            'Destination' => [
                'ToAddresses' => [$recipientEmail],
            ],
            'Message' => [
                'Subject' => [
                    'Data' => "Invoice #$invoiceId",
                    'Charset' => 'UTF-8',
                ],
                'Body' => [
                    'Html' => [
                        'Data' => "Your invoice #$invoiceId is ready. You can download it here: $pdfUrl",
                        'Charset' => 'UTF-8',
                    ],
                ],
            ],
        ]);

        // Publish EmailSent event
        try {
            $this->eventBridge->putEvents([
                'Entries' => [
                    [
                        'Source' => 'com.invoicemanagement.notification',
                        'DetailType' => 'EmailSent',
                        'Detail' => json_encode([
                            'invoiceId' => $invoiceId,
                            'recipientEmail' => $recipientEmail,
                        ]),
                        'EventBusName' => 'InvoiceManagementEventBus',
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            $log->error('Failed to publish event: ' . $e->getMessage());
            // Note: We're not rethrowing here as the email was successfully sent
        }

        return $response->withJson(['success' => true, 'messageId' => $result['MessageId']]);
    } catch (\Exception $e) {
        $log->error('Failed to send email: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'Failed to send email']);
    }
});

$app->run();
