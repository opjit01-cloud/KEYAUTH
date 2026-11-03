<?php

namespace misc\mysql;

function query($query, $args = [], $types = null)
{
        static $connection = null;
        static $readOnlyConnection = null;

        global $mysqlRequireSSL;
        global $databaseHost;
        global $databaseUsername;
        global $databasePassword;
        global $databaseName;
        global $databasePort;
        global $readOnlyRegions;

        $port = 3306;
        $host = $databaseHost;
        if (strpos($host, ':') !== false) {
                $parts = explode(':', $host);
                $host = $parts[0];
                $port = intval($parts[1]);
        }
        if (!empty($databasePort)) {
                $port = intval($databasePort);
        }

        // use read-only database replica, for lower latency in regions far away from the primary database region
        if(strtolower(substr($query, 0, 6)) == "select" && isset($readOnlyRegions[$_SERVER['SERVER_ADDR']])) {
                if (!$readOnlyConnection) {
                        $readOnlyConnection = new \mysqli();

                        $roHost = $readOnlyRegions[$_SERVER['SERVER_ADDR']]["host"];
                        $roPort = 3306;
                        if (strpos($roHost, ':') !== false) {
                                $roParts = explode(':', $roHost);
                                $roHost = $roParts[0];
                                $roPort = intval($roParts[1]);
                        }

                        $flags = 0;
                        if ($mysqlRequireSSL) {
                                $readOnlyConnection->ssl_set(NULL, NULL, NULL, NULL, NULL);
                                $flags = MYSQLI_CLIENT_SSL;
                        }

                        $readOnlyConnection->real_connect($roHost, $readOnlyRegions[$_SERVER['SERVER_ADDR']]["username"], $readOnlyRegions[$_SERVER['SERVER_ADDR']]["password"], $databaseName, $roPort, NULL, $flags);

                        if ($readOnlyConnection->connect_error)
                                die("Database Connection Error: " . $readOnlyConnection->connect_error);

                        $readOnlyConnection->set_charset('utf8mb4');
                }
                $connectionToUse = $readOnlyConnection;
        }
        else {
                if (!$connection) {
                        $connection = new \mysqli();

                        $flags = 0;
                        if ($mysqlRequireSSL) {
                                $connection->ssl_set(NULL, NULL, NULL, NULL, NULL);
                                $flags = MYSQLI_CLIENT_SSL;
                        }

                        $connection->real_connect($host, $databaseUsername, $databasePassword, $databaseName, $port, NULL, $flags);

                        if ($connection->connect_error)
                                die("Database Connection Error: " . $connection->connect_error);

                        $connection->set_charset('utf8mb4');
                }
                $connectionToUse = $connection;
        }

        if ($types === null && $args !== [])
                $types = str_repeat('s', count($args));

        $stmt = $connectionToUse->prepare($query);

        if (!$stmt)
                die("Query Prepare Error: " . $connectionToUse->error);

        if (strpos($query, '?') !== false)
                $stmt->bind_param($types, ...$args);

        $stmt->execute();

        $query = new \stdClass();
        $query->result = $stmt->get_result();
        $query->num_rows = $query->result ? $query->result->num_rows : 0;
        $query->affected_rows = $stmt->affected_rows;

        $stmt->close();

        return $query;
}
