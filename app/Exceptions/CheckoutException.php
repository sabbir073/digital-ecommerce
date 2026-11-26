<?php

namespace App\Exceptions;

use RuntimeException;

/** A problem the customer can read and act on (out of stock, bad coupon, payment not set up). */
class CheckoutException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'checkout')
    {
        parent::__construct($message);
    }
}
