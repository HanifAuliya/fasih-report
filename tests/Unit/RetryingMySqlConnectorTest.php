<?php

namespace Tests\Unit;

use App\Support\RetryingMySqlConnector;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class RetryingMySqlConnectorTest extends TestCase
{
    /**
     * Konektor yang gagal dengan pesan tertentu sebanyak $failures kali sebelum berhasil.
     *
     * @return RetryingMySqlConnector&object{attempts: int}
     */
    private function connector(int $failures, string $message): RetryingMySqlConnector
    {
        return new class($failures, $message) extends RetryingMySqlConnector
        {
            public int $attempts = 0;

            public function __construct(private int $failures, private string $message)
            {
                parent::__construct([0, 0, 0]);
            }

            protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
            {
                if (++$this->attempts <= $this->failures) {
                    throw new PDOException($this->message);
                }

                return new PDO('sqlite::memory:');
            }
        };
    }

    public function test_busy_database_is_retried_until_a_connection_is_free(): void
    {
        $connector = $this->connector(2, 'SQLSTATE[08004] [1040] Too many connections');

        $this->assertInstanceOf(PDO::class, $connector->createConnection('mysql:host=x', [], []));
        $this->assertSame(3, $connector->attempts);
    }

    public function test_gives_up_after_the_last_retry(): void
    {
        $connector = $this->connector(10, 'SQLSTATE[08004] [1040] Too many connections');

        try {
            $connector->createConnection('mysql:host=x', [], []);
            $this->fail('Seharusnya gagal setelah percobaan terakhir');
        } catch (PDOException) {
            $this->assertSame(4, $connector->attempts);
        }
    }

    public function test_other_errors_are_not_retried(): void
    {
        $connector = $this->connector(1, "SQLSTATE[HY000] [1049] Unknown database 'x'");

        $this->expectException(PDOException::class);

        try {
            $connector->createConnection('mysql:host=x', [], []);
        } finally {
            $this->assertSame(1, $connector->attempts);
        }
    }
}
