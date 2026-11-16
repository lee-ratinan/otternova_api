<?php

namespace App\Database\MySQLi;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\MySQLi\Connection as BaseMySQLiConnection;
use mysqli;
use Throwable;

/**
 * The framework's MySQLi connection with its session clock on UTC, so DEFAULT/ON UPDATE CURRENT_TIMESTAMP (created_at,
 * updated_at) and NOW() write the same UTC times that PHP does, whatever time zone the server runs in.
 * Config\Database switches every MySQLi group to this driver. DBDriver still reads 'MySQLi', so the app's
 * DBDriver checks and the framework's Forge and Utils work as before.
 */
class Connection extends BaseMySQLiConnection
{
    /** The session time zone, as an offset (it needs no time zone tables on the server) */
    public const SESSION_TIME_ZONE = '+00:00';

    public function __construct(array $params)
    {
        parent::__construct($params);

        $this->DBDriver = 'MySQLi';
    }

    /**
     * Connects as the framework does, then sets the session time zone; also runs on reconnect()
     * @return false|mysqli
     * @throws DatabaseException
     */
    public function connect(bool $persistent = false)
    {
        $mysqli = parent::connect($persistent);
        if ($mysqli === false) {
            return false;
        }

        try {
            $mysqli->query("SET time_zone = '" . self::SESSION_TIME_ZONE . "'");
        } catch (Throwable $e) {
            log_message('error', 'Database: Unable to set the session time zone: ' . $e->getMessage());
            $mysqli->close();

            throw new DatabaseException('Unable to set the session time zone to ' . self::SESSION_TIME_ZONE, 0, $e);
        }

        return $mysqli;
    }
}
