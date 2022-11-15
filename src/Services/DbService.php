<?php
namespace App\Services;

use App\Exceptions\Services\QueryException;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;

class DbService extends AbstractService
{
    protected string $host;
    protected string $port;
    protected string $user;
    protected string $password;
    protected string $db;
    protected PDO $pdo;
    protected bool $configured = false;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function setConnectionConfig(string $host, int $port, string $user, string $password, string $db): void
    {
        $this->host = $host;
        $this->port = $port ?? 3306;
        $this->user = $user;
        $this->password = $password;
        $this->db = $db;

        $this->configured = ($host && $user);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function escape($value): string
    {
        $phpType = gettype($value);

        if ($value === null) {
            $pdoType = PDO::PARAM_NULL;
        } elseif ($phpType === 'string') {
            $pdoType = PDO::PARAM_STR;
        } elseif ($phpType === 'integer') {
            $pdoType = PDO::PARAM_INT;
        } elseif ($phpType === 'boolean') {
            $pdoType = PDO::PARAM_BOOL;
        } else {
            throw new InvalidArgumentException(
                'Unsupported PHP to PDO type conversion. Supported types are: NULL, string, integer, boolean.'
            );
        }

        return $this->pdo->quote($value, $pdoType);
    }

    /**
     * @throws QueryException
     */
    public function executeSql(string $sql, $params = null): PDOStatement
    {
        $this->connect();

        $sth = $this->pdo->prepare($sql);

        try {
            $sth->execute($params);
        } catch (PDOException $ex) {
            throw new QueryException($ex->getMessage(), $ex->getCode(), $ex, $sql, $params);
        }

        return $sth;
    }

    public function fetchAssoc(string $sql, $params = null): array
    {
        $sth = $this->executeSql($sql, $params);
        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function connect(): void
    {
        if (!isset($this->pdo)) {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db}";
            $this->pdo = new PDO($dsn, $this->user, $this->password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    }
}
