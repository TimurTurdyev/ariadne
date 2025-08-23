<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Manticore;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use TimurTurdyev\SimpleSearch\Index\CatalogIndexUnavailable;

/**
 * Lazy connection to Manticore; an unreachable engine is CatalogIndexUnavailable. Not final: tests subclass it.
 */
class Connection
{
    private const DEFAULT_TIMEOUT = 5;

    private const DEFAULT_READ_TIMEOUT = 30;

    // A live engine greets within milliseconds.
    private const HANDSHAKE_TIMEOUT = 1;

    private const GONE_AWAY = 2006;

    private const LOST = 2013;

    private readonly int $timeout;

    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        int $timeout = self::DEFAULT_TIMEOUT,
        private readonly int $readTimeout = self::DEFAULT_READ_TIMEOUT,
    ) {
        $this->timeout = $timeout >= 1 ? $timeout : self::DEFAULT_TIMEOUT;
    }

    /**
     * @param  array<string, mixed>  $config  host, port, timeout, read_timeout
     */
    public static function fromConfig(array $config): self
    {
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;
        $timeout = $config['timeout'] ?? null;
        $readTimeout = $config['read_timeout'] ?? null;

        return new self(
            is_string($host) && $host !== '' ? $host : '127.0.0.1',
            is_numeric($port) ? (int) $port : 9306,
            is_numeric($timeout) ? (int) $timeout : self::DEFAULT_TIMEOUT,
            is_numeric($readTimeout) ? (int) $readTimeout : self::DEFAULT_READ_TIMEOUT,
        );
    }

    /**
     * Ints and bools are bound as numbers, the rest as strings: floats and lists go into the SQL text.
     *
     * @param  list<scalar|null>  $bindings
     */
    public function query(string $sql, array $bindings = []): PDOStatement
    {
        return $this->run(static function (PDO $pdo) use ($sql, $bindings): PDOStatement {
            $statement = $pdo->prepare($sql);

            foreach ($bindings as $index => $value) {
                $statement->bindValue($index + 1, $value, is_int($value) || is_bool($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }

            $statement->execute();

            return $statement;
        });
    }

    /**
     * @param  list<scalar|null>  $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->query($sql, $bindings)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function execute(string $sql): void
    {
        $this->run(static fn (PDO $pdo): int|false => $pdo->exec($sql));
    }

    /**
     * Quotes a string for a statement assembled by hand.
     */
    public function quote(string $value): string
    {
        return $this->pdo()->quote($value);
    }

    /**
     * A dropped connection is opened again: once more for a statement that never left, never for a lost one.
     *
     * @template T
     *
     * @param  Closure(PDO): T  $statement
     * @return T
     */
    private function run(Closure $statement): mixed
    {
        $pdo = $this->pdo();
        $started = hrtime(true);

        try {
            return $statement($pdo);
        } catch (PDOException $e) {
            $this->forgetBroken($e, $started);
        }

        $pdo = $this->pdo();
        $started = hrtime(true);

        try {
            return $statement($pdo);
        } catch (PDOException $e) {
            $this->forgetBroken($e, $started);

            throw $e;
        }
    }

    /**
     * @param  int|float  $started  hrtime of the statement start
     *
     * @throws PDOException any other error, as it is
     * @throws CatalogIndexUnavailable the connection broke during the statement or the answer timed out
     */
    private function forgetBroken(PDOException $e, int|float $started): void
    {
        $code = $e->errorInfo[1] ?? null;

        if ($code !== self::GONE_AWAY && $code !== self::LOST) {
            throw $e;
        }

        $this->pdo = null;

        if ($code === self::LOST) {
            throw new CatalogIndexUnavailable(
                "Manticore at {$this->host}:{$this->port} dropped the connection during a statement: ".$e->getMessage(),
                previous: $e,
            );
        }

        // mysqlnd reports an expired read timeout as 2006 too, but that statement did reach the engine.
        if ($this->readTimeout > 0 && hrtime(true) - $started >= $this->readTimeout * 0.9 * 1e9) {
            throw new CatalogIndexUnavailable(
                "Manticore at {$this->host}:{$this->port} did not answer within {$this->readTimeout} s: ".$e->getMessage(),
                previous: $e,
            );
        }
    }

    // pdo_mysql takes "localhost" for its unix socket and ignores the port.
    private function address(): string
    {
        return strtolower($this->host) === 'localhost' ? '127.0.0.1' : $this->host;
    }

    // An open port proves nothing: a forwarded port of a dead container accepts and never greets.
    private function answersHandshake(): bool
    {
        $socket = @fsockopen($this->address(), $this->port, $errno, $error, self::HANDSHAKE_TIMEOUT);

        if ($socket === false) {
            return false;
        }

        stream_set_timeout($socket, self::HANDSHAKE_TIMEOUT);
        $greeting = @fread($socket, 1);
        $timedOut = stream_get_meta_data($socket)['timed_out'];

        fclose($socket);

        return $greeting !== false && $greeting !== '' && ! $timedOut;
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if (! $this->answersHandshake()) {
            throw new CatalogIndexUnavailable("Manticore is unreachable at {$this->host}:{$this->port}: no handshake");
        }

        // mysqlnd reads the setting when it connects, so other connections of the process keep theirs.
        $previous = $this->readTimeout > 0 ? ini_set('mysqlnd.net_read_timeout', (string) $this->readTimeout) : false;

        try {
            return $this->pdo = new PDO(
                "mysql:host={$this->address()};port={$this->port}",
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => $this->timeout,
                    PDO::ATTR_EMULATE_PREPARES => true,
                ],
            );
        } catch (PDOException $e) {
            throw new CatalogIndexUnavailable(
                "Manticore is unreachable at {$this->host}:{$this->port}: ".$e->getMessage(),
                previous: $e,
            );
        } finally {
            if ($previous !== false) {
                ini_set('mysqlnd.net_read_timeout', $previous);
            }
        }
    }
}
