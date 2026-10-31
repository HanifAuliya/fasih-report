<?php

namespace App\Support;

use Illuminate\Database\Connectors\MySqlConnector;
use PDOException;
use Throwable;

/**
 * Koneksi MySQL yang menunggu sebentar lalu mencoba lagi bila server database sedang penuh
 * (hosting bersama: "Too many connections"), supaya lonjakan singkat tidak langsung jadi error 500.
 */
class RetryingMySqlConnector extends MySqlConnector
{
    /** Pesan error yang berarti database sedang penuh/sibuk, bukan salah konfigurasi. */
    private const BUSY_MESSAGES = ['Too many connections', 'max_user_connections', 'Resource temporarily unavailable'];

    /**
     * @param  list<int>  $retryDelaysMs  jeda sebelum tiap percobaan ulang (milidetik)
     */
    public function __construct(private array $retryDelaysMs = [200, 500, 1000, 1500]) {}

    /**
     * @param  string  $dsn
     * @param  array<string, mixed>  $config
     * @param  array<int, mixed>  $options
     */
    public function createConnection($dsn, array $config, array $options)
    {
        foreach ($this->retryDelaysMs as $delayMs) {
            try {
                return parent::createConnection($dsn, $config, $options);
            } catch (PDOException $e) {
                if (! $this->isBusy($e)) {
                    throw $e;
                }

                usleep($delayMs * 1000);
            }
        }

        return parent::createConnection($dsn, $config, $options);
    }

    private function isBusy(Throwable $e): bool
    {
        return str_contains($e->getMessage(), '[1040]')
            || array_any(self::BUSY_MESSAGES, fn (string $message) => str_contains($e->getMessage(), $message));
    }
}
