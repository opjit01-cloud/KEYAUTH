<?php

namespace misc\cache;

use misc\mysql;
use api\shared;

function fetch($redisKey, $sqlQuery, $args = [], $multiRowed = false, $expiry = null, $types = null)
{
        global $redis;
        include_once (isset($_SERVER['DOCUMENT_ROOT']) && ($_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/panel" || $_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/api") ? "/usr/share/nginx/html" : ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../..')) . '/includes/redis.php';
        
        if (!$redis) {
                $query = mysql\query($sqlQuery, $args, $types);
                if (!$query || $query->num_rows < 1) {
                        return 'not_found';
                }
                if ($multiRowed) {
                        $data = [];
                        while ($r = mysqli_fetch_assoc($query->result)) {
                                $data[] = $r;
                        }
                } else {
                        $data = mysqli_fetch_array($query->result);
                }
                return $data;
        }

        $redisKey = strtolower($redisKey);
        $data = $redis->get($redisKey);
        if (!$data) {
                $query = mysql\query($sqlQuery,$args, $types);
                if ($query->num_rows < 1)
                {
                        $redis->set($redisKey, 'not_found');
                        $redis->expire($redisKey, 300);
                        return 'not_found';
                }
                if ($multiRowed) {
                        while ($r = mysqli_fetch_assoc($query->result)) {
                                $data[] = $r;
                        }
                } else {
                        $data = mysqli_fetch_array($query->result);
                }

                $redis->set($redisKey, serialize($data));

                if(str_contains($redisKey, "keyauthsubs")) {
                        $expiries = array();
                        foreach ($data as $row) {
                                $expiries[] = $row['expiry'];
                        }
                        $ttl = intval(min($expiries) - time());
                        $redis->expire($redisKey, $ttl);
                }

                if(str_contains($redisKey, "keyauthsellercheck")) {
                        $ttl = intval($data["expires"] - time());
                        $redis->expire($redisKey, $ttl);
                }

                if(str_contains($redisKey, "keyauthstate")) {
                        $ttl = intval($data["expiry"] - time());
                        $redis->expire($redisKey, $ttl);
                }

                if (!is_null($expiry)) {
                        $redis->expire($redisKey, intval($expiry));
                }
        } else {
                if ($data == "not_found") {
                        return 'not_found';
                }
                $data = unserialize($data);
        }
        return $data;
}

function purge($redisKey)
{
        global $redis;
        include_once (isset($_SERVER['DOCUMENT_ROOT']) && ($_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/panel" || $_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/api") ? "/usr/share/nginx/html" : ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../..')) . '/includes/redis.php';
        if (!$redis) return;

        $redisKey = strtolower($redisKey);
        global $redisServers;
        if(!empty($redisServers)) {
                $redisKey = urlencode($redisKey);
                foreach ($redisServers as $server) {
                        $url = $server . "&type=purge&key={$redisKey}";
                        $curl = curl_init($url);
                        curl_setopt($curl, CURLOPT_URL, $url);
                        curl_setopt($curl, CURLOPT_HTTPHEADER, array('host: keyauth.win'));
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        curl_exec($curl);
                        curl_close($curl);
                }
                return;
        }

        $redis->del($redisKey);
}

function purgePattern($redisKey)
{
        global $redis;
        include_once (isset($_SERVER['DOCUMENT_ROOT']) && ($_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/panel" || $_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/api") ? "/usr/share/nginx/html" : ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../..')) . '/includes/redis.php';
        if (!$redis) return;

        if($redisKey == "*" || empty($redisKey)) {
                die("Invalid redis key purge value. Must specify some text.");
        }
        $redisKey = strtolower($redisKey);
        global $redisServers;
        if(!empty($redisServers)) {
                $redisKey = urlencode($redisKey);
                foreach ($redisServers as $server) {
                        $url = $server . "&type=purgePattern&key={$redisKey}";
                        $curl = curl_init($url);
                        curl_setopt($curl, CURLOPT_URL, $url);
                        curl_setopt($curl, CURLOPT_HTTPHEADER, array('host: keyauth.win'));
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        curl_exec($curl);
                        curl_close($curl);
                }
                return;
        }
        
        $redis->delete($redis->keys($redisKey . '*'));
}

function rateLimit($redisKey, $amount, $expiry, $limit)
{
        global $redis;
        include_once (isset($_SERVER['DOCUMENT_ROOT']) && ($_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/panel" || $_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/api") ? "/usr/share/nginx/html" : ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../..')) . '/includes/redis.php';
        if (!$redis) return false;

        $redisKey = strtolower($redisKey);
        $data = $redis->get($redisKey);
        if (!$data) {
                $redis->set($redisKey, $amount, $expiry);
                return false;
        } else {
                if (intval($data) >= $limit) {
                        $ttl = $redis->ttl($redisKey);
                        if($ttl > $expiry || $ttl < 0) {
                                purge($redisKey);
                                return false;
                        }
                        return true;
                }
                $redis->incr($redisKey, $amount);
                return false;
        }
}

function select($redisKey) {
        global $redis;
        include_once (isset($_SERVER['DOCUMENT_ROOT']) && ($_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/panel" || $_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/api") ? "/usr/share/nginx/html" : ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../..')) . '/includes/redis.php';
        if (!$redis) return false;
        $redisKey = strtolower($redisKey);
        return $redis->get($redisKey);
}

function insert($redisKey, $value, $expiry) {
        global $redis;
        include_once (isset($_SERVER['DOCUMENT_ROOT']) && ($_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/panel" || $_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/api") ? "/usr/share/nginx/html" : ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../..')) . '/includes/redis.php';
        if (!$redis) return;

        $redisKey = strtolower($redisKey);
        global $redisServers;
        if(!empty($redisServers)) {
                $data = base64_encode($value);
                foreach ($redisServers as $server) {
                        $url = $server . "&type=insert&key={$redisKey}&data={$data}&expiry={$expiry}";
                        $curl = curl_init($url);
                        curl_setopt($curl, CURLOPT_URL, $url);
                        curl_setopt($curl, CURLOPT_HTTPHEADER, array('host: keyauth.win'));
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        curl_exec($curl);
                        curl_close($curl);
                }
                return;
        }

        $redis->set($redisKey, $value, $expiry);
}

function update($redisKey, ...$replacements) {
        global $redis;
        include_once (isset($_SERVER['DOCUMENT_ROOT']) && ($_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/panel" || $_SERVER['DOCUMENT_ROOT'] == "/usr/share/nginx/html/api") ? "/usr/share/nginx/html" : ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../..')) . '/includes/redis.php';
        if (!$redis) return true;

        $redisKey = strtolower($redisKey);
        $data = unserialize($redis->get($redisKey));
        if(!$data) return false;

        global $redisServers;
        if(!empty($redisServers)) {
                foreach ($redisServers as $server) {
                        $url = $server . "&type=update&key={$redisKey}";
                        $json_data = json_encode(["data" => $replacements[0]]);
                        $curl = curl_init($url);
                        curl_setopt($curl, CURLOPT_URL, $url);
                        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                                'Content-type: application/json',
                                'host: keyauth.win'
                        ));
                        curl_setopt($curl, CURLOPT_POST, 1);
                        curl_setopt($curl, CURLOPT_POSTFIELDS, $json_data);
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        curl_exec($curl);
                        curl_close($curl);
                }
                return true;
        }

        $new = serialize(array_replace($data, ...$replacements));
        $redis->set($redisKey, $new, ['KEEPTTL']);
        return true;
}
