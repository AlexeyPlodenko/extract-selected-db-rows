<?php
namespace App\Exceptions\App;

use App\Exceptions\AbstractException;
use Throwable;

class SameServerException extends AbstractException
{
    protected string $db;

    public function __construct($message = "", $code = 0, Throwable $previous = null, string $db = null)
    {
        parent::__construct($message, $code, $previous);

        $this->db = $db;
    }

    public function getDb(): string
    {
        return $this->db;
    }
}
