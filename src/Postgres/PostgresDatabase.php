<?php

declare(strict_types=1);

namespace VUdaltsov\UuidVsAutoIncrement\Postgres;

use PgSql\Connection;
use PgSql\Result;
use VUdaltsov\UuidVsAutoIncrement\AutoIncrementBenchmark\AutoIncrementTable;
use VUdaltsov\UuidVsAutoIncrement\Database\Database;
use VUdaltsov\UuidVsAutoIncrement\Database\Table;
use VUdaltsov\UuidVsAutoIncrement\Stopwatch\Memory;
use VUdaltsov\UuidVsAutoIncrement\Stopwatch\TimePeriod;
use VUdaltsov\UuidVsAutoIncrement\UuidBenchmark\UuidTable;

final class PostgresDatabase implements Database
{
    private readonly Connection $connection;

    /**
     * @param non-empty-string $dbName
     * @param non-empty-string $user
     * @param non-empty-string $password
     * @param non-empty-string $host
     * @param positive-int     $port
     */
    public function __construct(
        string $host = 'localhost',
        int $port = 5432,
        string $dbName = 'root',
        string $user = 'root',
        string $password = 'root',
    ) {
        $this->connection = pg_connect("host={$host} port={$port} dbname={$dbName} user={$user} password={$password}");
    }

    /**
     * @template T of Table
     * @param class-string<T> $class
     * @return ?T
     */
    public function createTable(string $class): ?Table
    {
        /** @var ?T */
        return match ($class) {
            AutoIncrementTable::class => new PostgresAutoIncrementTable($this),
            UuidTable::class => new PostgresUuidTable($this),
            default => null,
        };
    }

    public function execute(string $query): Result
    {
        return pg_query($this->connection, $query);
    }

    public function measureIndexesSize(string $table): Memory
    {
        /** @var numeric-string */
        $bytes = pg_fetch_result(
            result: $this->execute(
                <<<SQL
                    select pg_indexes_size('{$table}'::regclass)
                    SQL,
            ),
            row: 0,
            field: 0,
        ) ?: throw new \RuntimeException(sprintf('Failed to get indexes size of "%s".', $table));

        return new Memory((int) $bytes);
    }

    public function measureExecutionTime(string $query): TimePeriod
    {
        /** @var non-empty-list<string> */
        $explain = pg_fetch_all_columns(
            result: $this->execute('explain (analyze true, timing false) '.$query),
        );

        if (!preg_match('/Execution Time: ([\d.]+)/i', implode('', $explain), $matches)) {
            throw new \RuntimeException('Failed to get execution time.');
        }

        $milliseconds = (float) $matches[1];

        return new TimePeriod((int) ($milliseconds * 1000));
    }
}
