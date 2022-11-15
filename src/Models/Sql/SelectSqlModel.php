<?php
namespace App\Models\Sql;

use App\Exceptions\Models\InvalidSqlQueryException;
use App\Exceptions\Models\UnsupportedNestedQueryException;
use App\Models\AbstractModel;
use PHPSQLParser\Options;
use PHPSQLParser\PHPSQLCreator;
use PHPSQLParser\PHPSQLParser;
use PHPSQLParser\processors\SelectProcessor;

class SelectSqlModel extends AbstractModel
{
    const SQL_SELECT_MODE_REPLACE = 1;
    const SQL_SELECT_MODE_APPEND = 2;

    protected string $sql;
    protected string $defaultDb;
    protected array $sqlParsed;
    protected string $sqlSelect;
    protected int $sqlSelectMode;

    public function setDefaultDb(string $dbName): void
    {
        $this->defaultDb = $dbName;
    }

    /**
     * @throws InvalidSqlQueryException
     */
    public function setSql(string $sql)
    {
        $sql = trim($sql);
        if (!preg_match('/^\(*SELECT/i', $sql)) {
            throw new InvalidSqlQueryException();
        }

        $this->sql = $sql;
    }

    public function setSelect(string $sqlSelect): void
    {
        $this->sqlSelect = $sqlSelect;
        $this->sqlSelectMode = static::SQL_SELECT_MODE_REPLACE;
    }

    public function appendSelect(string $sqlSelect): void
    {
        $this->sqlSelect = $sqlSelect;
        $this->sqlSelectMode = static::SQL_SELECT_MODE_APPEND;
    }

    /**
     * @throws UnsupportedNestedQueryException
     */
    public function getTables(): array
    {
        $this->parseSql();

        $sources = [];
        if (isset($this->sqlParsed['FROM'])) {
            foreach ($this->sqlParsed['FROM'] as $from) {
                if ($from['sub_tree']) {
                    throw new UnsupportedNestedQueryException();
                }

                $fromString = str_replace('`', null, $from['table']);
                $fromArray = explode('.', $fromString);
                $sources[] = [
                    'db' => isset($fromArray[1]) ? $fromArray[0] : $this->defaultDb,
                    'table' => $fromArray[1] ?? $fromArray[0],
                    'alias' => $from['alias']['name'] ?? null
                ];
            }
        }

        return $sources;
    }

    public function getSql(): string
    {
        $this->parseSql();

        if ($this->sqlSelect) {
            $processorOptions = new Options([]);
            $processor = new SelectProcessor($processorOptions);
            $selectTokens = $processor->splitSQLIntoTokens($this->sqlSelect);

            switch ($this->sqlSelectMode) {
                case static::SQL_SELECT_MODE_REPLACE:
                    $this->sqlParsed['SELECT'] = $processor->process($selectTokens);
                    break;

                case static::SQL_SELECT_MODE_APPEND:
                    // fixing the missing coma
                    $lastSelectIndex = array_key_last($this->sqlParsed['SELECT']);
                    $this->sqlParsed['SELECT'][$lastSelectIndex]['delim'] = ',';

                    $this->sqlParsed['SELECT'] = array_merge(
                        $this->sqlParsed['SELECT'],
                        $processor->process($selectTokens)
                    );
                    break;
            }
        }

        $sqlCreator = new PHPSQLCreator($this->sqlParsed);
        return $sqlCreator->created;
    }

    protected function parseSql(): void
    {
        if (!isset($this->sqlParsed)) {
            $parser = new PHPSQLParser();
            $this->sqlParsed = $parser->parse($this->sql);
        }
    }
}
