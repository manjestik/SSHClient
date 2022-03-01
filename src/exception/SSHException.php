<?php

namespace App\Service\Ssh;

use Exception;

class SSHException extends Exception
{
    protected $message = 'Unknown SSH exception';
    protected $code = 500;
}
