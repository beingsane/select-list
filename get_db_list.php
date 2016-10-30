<?php
require_once 'config.php';

$config = new Config();
$db = new PDO("mysql:host=" . $config->db_host.";dbname=".$config->db_name, $config->db_username, $config->db_password);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('SET names utf8');
$db->exec('SET storage_engine=innoDB');
$db->exec("SET sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION,PIPES_AS_CONCAT,ONLY_FULL_GROUP_BY'");

$term = isset($_REQUEST['term'])?$_REQUEST['term']:'';
$doc_id = isset($_REQUEST['doc_id'])?$_REQUEST['doc_id']:null;
$page = @$_REQUEST['page']*1?$_REQUEST['page']:1;
//$per_page = 1000;
$per_page = @$_REQUEST['page']*1?$_REQUEST['per_page']:50;

$sql = "SELECT SQL_NO_CACHE CONCAT(m.name
, ' - ', IFNULL(TRIM(get_client_price(d.org_id_client, m.id, d.date)) + 0, '')
, ' - ', IFNULL(cs.kol, '')
) name
FROM materials m
LEFT JOIN docs d ON d.id = :doc_id
LEFT JOIN cur_stock cs ON cs.org_id = d.org_id_addr AND m.id = cs.mat_id
WHERE m.name LIKE CONCAT('%', IFNULL(:term, ''), '%')
 AND NOW() = NOW()
ORDER BY m.name
";
$sql .= PHP_EOL.'LIMIT '.(($page - 1) * $per_page).', '.$per_page;
$q = $db->prepare($sql);
$q->execute(['doc_id' => $doc_id, 'term' => $term]);
$q->setFetchMode(PDO::FETCH_ASSOC);
$data = $q->fetchAll();
$return = [];
$return['total_count'] = ($page - 1) * $per_page + (sizeof($data) < $per_page?sizeof($data):($per_page+1));
$return['items'] = $data;
$return['time'] = sprintf("%.6f", microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]);
echo json_encode($return);
