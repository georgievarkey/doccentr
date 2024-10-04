<?php
// pdf-service/index.php
require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Ssm\SsmClient;
use Aws\EventBridge\EventBridgeClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use FPDF;

$app = new \Slim\App();

// Set up logging
$log = new Logger('pdf-service');
$log->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));

// Set up S3 client
$container = $app->getContainer();
$container['s3'] = function ($c) use ($log) {
    return new S3Client([
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

$app->post('/generate-pdf', function ($request, $response) use ($log) {
    $data = $request->getParsedBody();
    $invoiceId = $data['invoiceId'];

    try {
        // Generate PDF (you'd fetch actual invoice data here)
        $pdf = new FPDF();
        $pdf->AddPage
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(40, 10, 'Invoice #' . $invoiceId);
        $pdfContent = $pdf->Output('S');

        // Upload to S3
        $ssmClient = new SsmClient([
            'version' => 'latest',
            'region'  => getenv('AWS_REGION')
        ]);

        try {
            $result = $ssmClient->getParameter([
                'Name' => 'pdf_bucket_name',
                'WithDecryption' => true
            ]);
            $bucketName = $result['Parameter']['Value'];
        } catch (\Exception $e) {
            $log->error('Failed to retrieve S3 bucket name: ' . $e->getMessage());
            throw new \Exception('Failed to retrieve S3 bucket name');
        }

        $key = 'invoice_' . $invoiceId . '.pdf';
        $result = $this->s3->putObject([
            'Bucket' => $bucketName,
            'Key'    => $key,
            'Body'   => $pdfContent,
            'ContentType' => 'application/pdf',
        ]);

        // Publish PDFGenerated event
        try {
            $this->eventBridge->putEvents([
                'Entries' => [
                    [
                        'Source' => 'com.invoicemanagement.pdf',
                        'DetailType' => 'PDFGenerated',
                        'Detail' => json_encode([
                            'invoiceId' => $invoiceId,
                            'pdfUrl' => $result['ObjectUrl'],
                        ]),
                        'EventBusName' => 'InvoiceManagementEventBus',
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            $log->error('Failed to publish event: ' . $e->getMessage());
            // Note: We're not rethrowing here as the PDF was successfully generated and uploaded
        }

        return $response->withJson(['success' => true, 'pdfUrl' => $result['ObjectUrl']]);
    } catch (\Exception $e) {
        $log->error('Failed to generate or upload PDF: ' . $e->getMessage());
        return $response->withStatus(500)->withJson(['error' => 'Failed to generate or upload PDF']);
    }
});

$app->run();
