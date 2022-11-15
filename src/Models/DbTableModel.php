<?php
namespace App\Models;

use App\Services\DbService;

class DbTableModel extends AbstractModel
{
    protected DbService $dbService;
    protected string $table;
    protected string $db;
    protected string $alias;

    public function __construct(DbService $dbService = null)
    {
        if ($dbService) {
            $this->setDbService($dbService);
        }
    }

    public function setDbService(DbService $dbService): void
    {
        $this->dbService = $dbService;
    }

    public function setTable(string $table, string $db = null, string $alias = null): void
    {
        $this->table = $table;
        if ($db) {
            $this->db = $db;
        }
        if ($alias) {
            $this->alias = $alias;
        }
    }

    /**
     * A combination of these rows, allow to uniquely identify rows in the table.
     */
    public function getTableIdColumns(): array
    {
        assert(!!$this->dbService);

        $primaryIndex = [];
        $uniqueIndexes = [];

        $sqlTableName = $this->getSqlTableName();
        $sql = "SHOW INDEXES FROM $sqlTableName";
        $indexes = $this->dbService->fetchAssoc($sql);
        foreach ($indexes as $index) {
            if ($index['Key_name'] === 'PRIMARY') {
                $primaryIndex[] = $index['Column_name'];

            } elseif ($index['Non_unique'] === '0') {
                $indexName = $index['Key_name'];

                if (!isset($uniqueIndexes[$indexName])) {
                    $uniqueIndexes[$indexName] = [];
                }
                $uniqueIndexes[$indexName][] = $index['Column_name'];
            }
        }

        return $primaryIndex ?? reset($uniqueIndexes);
    }

    public function getSqlTableName(): string
    {
        assert(!!$this->table);

        if ($this->db) {
            $sqlTableName = "`{$this->db}`.`{$this->table}`";
        } else {
            $sqlTableName = "`{$this->table}`";
        }

        return $sqlTableName;
    }

    public function getSqlTableNameWithAlias(): string
    {
        assert(!!$this->alias);

        if ($this->db) {
            $sqlTableNameWithAlias = "`{$this->db}`.`{$this->table}`";
        } else {
            $sqlTableNameWithAlias = "`{$this->table}`";
        }

        return "{$sqlTableNameWithAlias} AS `{$this->alias}`";
    }
}
