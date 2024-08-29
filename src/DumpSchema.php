<?php

namespace BeyondCode\LaravelMaskedDumper;

use BeyondCode\LaravelMaskedDumper\TableDefinitions\TableDefinition;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use Faker\Factory;
use Illuminate\Support\Facades\DB;

class DumpSchema
{
    protected $connectionName;
    protected $availableTables = [];
    protected $dumpTables = [];

    protected $loadAllTables = false;
    protected $customizedTables = [];

    protected array $driverMapping = [
        'db2'        => 'ibm_db2',
        'mssql'      => 'pdo_sqlsrv',
        'mysql'      => 'pdo_mysql',
        'mysql2'     => 'pdo_mysql', // Amazon RDS, for some weird reason
        'postgres'   => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql'      => 'pdo_pgsql',
        'sqlite'     => 'pdo_sqlite',
        'sqlite3'    => 'pdo_sqlite',
    ];

    public function __construct($connectionName = null)
    {
        $this->connectionName = $connectionName;
    }

    public static function define($connectionName = null)
    {
        return new static($connectionName);
    }

    public function schemaOnly(string $tableName)
    {
        return $this->table($tableName, function (TableDefinition $table) {
            $table->schemaOnly();
        });
    }

    public function table(string $tableName, callable $tableDefinition)
    {
        $this->customizedTables[$tableName] = $tableDefinition;

        return $this;
    }

    public function allTables()
    {
        $this->loadAllTables = true;

        return $this;
    }

    /**
     * @return \Illuminate\Database\ConnectionInterface
     */
    public function getConnection()
    {
        return DB::connection($this->connectionName);
    }

    public function getDoctrineConnection()
    {
        $driverName = $this->getConnection()->getDriverName();
        $driver = $this->driverMapping[$driverName] ?? null;

        if (!$driver) {
            throw new \Exception("Unsupported driver: $driverName");
        }

        $connectionParams = [
            'dbname' => $this->getConnection()->getDatabaseName(),
            'user' => $this->getConnection()->getConfig('user'),
            'password' => $this->getConnection()->getConfig('password'),
            'host' => $this->getConnection()->getConfig('host'),
            'driver' => $driver,
            'port' => $this->getConnection()->getConfig('port'),
            'charset' => $this->getConnection()->getConfig('charset'),
        ];

        $config = new Configuration();

        return DriverManager::getConnection($connectionParams, $config);
    }

    protected function getTable(string $tableName)
    {
        $table = collect($this->availableTables)->first(function (Table $table) use ($tableName) {
            return $table->getName() === $tableName;
        });

        if (is_null($table)) {
            throw new \Exception("Invalid table name {$tableName}");
        }

        return $table;
    }

    /**
     * @return TableDefinition[]
     */
    public function getDumpTables()
    {
        return $this->dumpTables;
    }

    protected function loadAvailableTables()
    {
        if ($this->availableTables !== []) {
            return;
        }

        $this->availableTables = $this->getDoctrineConnection()->createSchemaManager()->listTables();
    }

    public function load()
    {
        $this->loadAvailableTables();

        if ($this->loadAllTables) {
            $this->dumpTables = collect($this->availableTables)->mapWithKeys(function (Table $table) {
                return [$table->getName() => new TableDefinition($table)];
            })->toArray();
        }

        foreach ($this->customizedTables as $tableName => $tableDefinition) {
            $table = new TableDefinition($this->getTable($tableName));
            call_user_func_array($tableDefinition, [$table, Factory::create()]);

            $this->dumpTables[$tableName] = $table;
        }
    }
}
