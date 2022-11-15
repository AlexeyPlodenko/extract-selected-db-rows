<?php
namespace App\Exceptions\Services;

use App\Exceptions\AbstractException;
use Throwable;

class QueryException extends AbstractException
{
    protected string $sql;
    protected ?array $params;

    public function __construct($message = "", $code = 0, Throwable $previous = null, string $sql = null, ?array $params = null)
    {
        parent::__construct($message, $code, $previous);

        $this->sql = $sql;
        $this->params = $params;
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getParams(): ?array
    {
        return $this->params;
    }
}
