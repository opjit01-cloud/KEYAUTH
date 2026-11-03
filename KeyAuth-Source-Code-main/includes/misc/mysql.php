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

                // Auto-create all tables if 'accounts' table does not exist
                static $autoInitDone = false;
                if (!$autoInitDone) {
                        $autoInitDone = true;
                        $chk = @$connection->query("SHOW TABLES LIKE 'accounts'");
                        if ($chk && $chk->num_rows === 0) {
                                $sqlDoc = (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) ? $_SERVER['DOCUMENT_ROOT'] : __DIR__ . '/../..';
                                $sqlFile = $sqlDoc . '/db_structure.sql';
                                if (!file_exists($sqlFile)) {
                                        $sqlFile = __DIR__ . '/../../db_structure.sql';
                                }
                                if (file_exists($sqlFile)) {
                                        $rawSql = file_get_contents($sqlFile);
                                        $stmts = preg_split('/;\s*[\r\n]+/', $rawSql);
                                        foreach ($stmts as $st) {
                                                $trimmed = trim($st);
                                                if (!empty($trimmed)) {
                                                        @$connection->query($trimmed);
                                                }
                                        }
                                }
                        }
                }
        }
        $connectionToUse = $connection;

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
