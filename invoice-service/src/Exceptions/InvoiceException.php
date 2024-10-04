<?php
// src/Exceptions/InvoiceException.php
namespace App\Exceptions;

use Exception;

class InvoiceException extends Exception
{
    public function __construct($message, $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
