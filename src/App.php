<?php
namespace App;

use App\Exceptions\App\AppException;
use App\Exceptions\App\MissingIdColumnsException;
use App\Exceptions\App\SameServerException;
use App\Exceptions\Models\InvalidSqlQueryException;
use App\Exceptions\Models\UnsupportedNestedQueryException;
use App\Exceptions\Services\QueryException;
use App\Models\DbTableModel;
use App\Models\Sql\SelectSqlModel;
use App\Services\DbService;
use RuntimeException;

class App
{
    protected array $config;
    protected array $log;
    protected DbService $srcDbService;
    protected DbService $dstDbService;
    protected bool $isCli;

    public function __construct()
    {
        $this->srcDbService = new DbService();
        $this->dstDbService = new DbService();
    }

    public function isCli(): bool
    {
        if (!isset($this->isCli)) {
            $this->isCli = (php_sapi_name() === 'cli');
        }

        return $this->isCli;
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;

        $this->srcDbService->setConnectionConfig(
            $this->config['srcDbHost'],
            $this->config['srcDbPort'],
            $this->config['srcDbUser'],
            $this->config['srcDbPassword'],
            $this->config['srcDbDefault'],
        );

        $this->dstDbService->setConnectionConfig(
            $this->config['dstDbHost'],
            $this->config['dstDbPort'],
            $this->config['dstDbUser'],
            $this->config['dstDbPassword'],
            $this->config['dstDbDefault'],
        );
    }

    /**
     * @throws AppException
     */
    public function processLogFile(): void
    {
        if (!$this->srcDbService->isConfigured()) {
            throw new RuntimeException(
                'The source DB configuration is not set. '
                .'Set the $config values properly in App->setConfig($config);'
            );
        }
        if (!$this->dstDbService->isConfigured()) {
            throw new RuntimeException(
                'The destination DB configuration is not set. '
                .'Set the $config values properly in App->setConfig($config);'
            );
        }

        $this->loadLogFile();
        if (!$this->log) {
            throw new AppException('Log file is empty.');
        }

        $selectExists = false;
        foreach ($this->log as $sql) {
            $select = new SelectSqlModel();
            $select->setDefaultDb($this->config['srcDbDefault']);
            try {
                $select->setSql($sql);
                $selectExists = true;
                $tables = $select->getTables();
                $idSelectCounter = 0;
                $idSelect = [];
                $idMapping = [];
                foreach ($tables as $table) {
                    $tableModel = new DbTableModel($this->srcDbService);
                    $tableModel->setTable($table['table'], $table['db']);

                    $idColumns = $tableModel->getTableIdColumns();
                    if (!$idColumns) {
                        throw new MissingIdColumnsException();
                    }

                    foreach ($idColumns as $idColumn) {
                        if ($table['alias']) {
                            $idColumnTable = "`{$table['alias']}`";
                        } else {
                            $idColumnTable = $table['db'] ? "`{$table['db']}`.`{$table['table']}`" : "`{$table['table']}`";
                        }

                        $idAlias = "__xtrct_slctd_{$idSelectCounter}";
                        $idSelect[] = "{$idColumnTable}.`$idColumn` AS `{$idAlias}`";
                        $idMapping[$idAlias] = [
                            'table' => $table,
                            'idColumn' => $idColumn
                        ];
                        $idSelectCounter++;
                    }
                    $selectSql = implode(', ', $idSelect);
                    $select->appendSelect($selectSql);
                }
                $idSelectSql = $select->getSql();
                $idRows = $this->srcDbService->fetchAssoc($idSelectSql);

                // now we can replicate the rows from the source database into the destination one
                $tableModel = new DbTableModel($this->srcDbService);
                foreach ($idRows as $idRow) {
                    // one row at a time

                    // grouping values into tables
                    $selects = [];
                    foreach ($idMapping as $idKey => $idMap) {
                        $tableKey = json_encode($idMap['table']);

                        if (!isset($selects[$tableKey])) {
                            $selects[$tableKey] = $idMap;
                            $selects[$tableKey]['values'] = [];
                        }

                        $selects[$tableKey]['values'][$idMap['idColumn']] = $idRow[$idKey];

                    }

                    foreach ($selects as $select) {
                        assert(!!$select['values']);

                        $table =& $select['table'];
                        $tableModel->setTable($table['table'], $table['db'], $table['alias']);
                        $tableNameSql = $tableModel->getSqlTableName();

                        $whereSqlArray = [];
                        $whereParams = [];
                        foreach ($select['values'] as $column => $value) {
                            $whereSqlArray[] = "`$column` = ?";
                            $whereParams[] = $value;
                        }
                        $whereSql = implode(' AND ', $whereSqlArray);

                        $row = $this->srcDbService->fetchAssoc(
                            "SELECT *
                            FROM {$tableNameSql}
                            WHERE {$whereSql}",
                            $whereParams
                        );
                        if ($row) {
                            assert(!isset($row[1]));

                            if (isset($this->config['srcToDstDbMapping'][$table['db']])) {
                                $tableModel->setTable(
                                    $table['table'],
                                    $this->config['srcToDstDbMapping'][$table['db']],
                                    $table['alias']
                                );
                                $tableNameSql = $tableModel->getSqlTableName();
                            } elseif (
                                   $this->config['srcDbHost'] === $this->config['dstDbHost']
                                && $this->config['srcDbPort'] === $this->config['dstDbPort']
                            ) {
                                throw new SameServerException(null, null, null, $table['db']);
                            }

                            $insertSqlArray = [];
                            $insertParams = [];
                            foreach ($row[0] as $column => $value) {
                                $insertSqlArray[] = "`{$column}` = ?";
                                $insertParams[] = $value;
                            }
                            $insertSql = implode(', ', $insertSqlArray);

                            $this->dstDbService->executeSql(
                                "INSERT IGNORE INTO {$tableNameSql}
                                SET {$insertSql}",
                                $insertParams
                            );
                        }
                    }
                    unset($table);
                }

            } catch (InvalidSqlQueryException $ex) {
                // this is not a SELECT query, skipping
                // noop

            } catch (UnsupportedNestedQueryException $ex) {
                $this->outputLine('App. error. The nested queries parsing is not supported yet. The failed query:');
                $this->outputPreFormattedLine($sql);

                if ($this->config['stopOnUnsupportedNestedQueryException']) {
                    exit;
                }

            } catch (MissingIdColumnsException $ex) {
                $this->outputLine(
                    'App. error. Failed to find the identifying columns for the query. '
                    .'Probably there are no PRIMARY or UNIQUE indexes setup for one of the tables in the query. '
                    .'The failed query:'
                );
                $this->outputPreFormattedLine($sql);

                if ($this->config['stopOnMissingIdColumnsException']) {
                    exit;
                }

            } catch (SameServerException $ex) {
                $db = $ex->getDb();
                $this->outputLine(
                    'App. error. The source and the destination servers are the same. '
                    ."And there is a missing database mapping for the database \"{$db}\". "
                    .'So we are aborting to prevent the data corruption in the source database.'
                );

                exit; // this is critical, as can lead to the data corruption. So we are stopping here

            } catch (QueryException $ex) {
                $exMsg = $ex->getMessage();
                $exTraceString = $ex->getTraceAsString();
                $exSql = $ex->getSql();
                $exParams = $ex->getParams();

                $this->outputLine("MySQL error. $exMsg. SQL:");
                $this->outputPreFormattedLine($exSql);
                $this->outputPreFormattedLine($exParams);
                $this->outputLine('Trace:');
                $this->outputPreFormattedLine($exTraceString);

                exit; // something went wrong. So we are stopping here
            }
        }

        if (!$selectExists) {
            throw new AppException('Log file does not contain any SELECT queries.');
        }

        $this->outputLine('Done.');
    }

    protected function loadLogFile(): void
    {
        if (!isset($this->log)) {
            $logData = file_get_contents($this->config['logFilePath']);
            $logData = str_replace("\r", null, $logData);
            $this->log = explode("\n", $logData);
        }
    }

    protected function outputLine(string $line): void
    {
        $isCli = $this->isCli();
        if (!$isCli) {
            echo "{$line}<br>";
        } else {
            $eol = PHP_EOL;
            echo "{$line}{$eol}";
        }
    }

    protected function outputPreFormattedLine(string $line): void
    {
        $isCli = $this->isCli();
        if (!$isCli) {
            echo "<pre style=\"white-space: pre-wrap;\">{$line}</pre><br>";
        } else {
            print_r($line);
            echo PHP_EOL;
        }
    }
}
