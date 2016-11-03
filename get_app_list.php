<?php
require_once 'config.php';

$memcache = new Memcache;
$memcache->connect('127.0.0.1', 11211);

$config = new Config();
$db = new PDO("mysql:host=" . $config->db_host.";dbname=".$config->db_name, $config->db_username, $config->db_password);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('SET names utf8');
//$db->exec('SET storage_engine=innoDB');
$db->exec("SET sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION,PIPES_AS_CONCAT,ONLY_FULL_GROUP_BY'");

$term = isset($_REQUEST['term'])?$_REQUEST['term']:'';
$doc_id = isset($_REQUEST['doc_id'])?$_REQUEST['doc_id']:null;
$page = @$_REQUEST['page']*1?$_REQUEST['page']:1;
//$per_page = 1000;
$per_page = @$_REQUEST['per_page'] ?: 50;

// Построением sql запросов будет заниматься ОРМ, но для простоты пишу итоговые запросы который сгенерит ОРМ
// Документ
$sql = "SELECT SQL_NO_CACHE * FROM docs WHERE id = :doc_id AND NOW() = NOW()";
$q = $db->prepare($sql);
$q->execute(['doc_id' => $doc_id]);
$doc = $q->fetch();

// Материалы
$sql = "
    SELECT SQL_NO_CACHE m.id, m.name, m.manufact_id, cs.kol
    FROM materials m
        LEFT JOIN cur_stock cs ON cs.org_id = :org_id AND m.id = cs.mat_id
    WHERE m.name LIKE CONCAT('%', :term, '%')
        AND NOW() = NOW()
    LIMIT ".(($page - 1) * $per_page).', '.$per_page."
";
$q = $db->prepare($sql);
$q->execute(['org_id' => $doc['org_id_addr'], 'term' => $term]);
$materials = $q->fetchAll();

function get_client_price($mat, $doc, $memcache)
{
    $key = 'client_price:' . $mat['id'] . $doc['id'];
    $price = $memcache->get($key);
    if ($price !== false) {
        return $price;
    }


	global $db;

	// Определяем скидку по клиенту

	// Скидка по товару
	$sql = "
        SELECT SQL_NO_CACHE cp.price_type_id, cp.discount
        FROM client_price cp
        WHERE cp.org_id = :org_id
            AND cp.mat_id = :mat_id
            AND cp.org_id_manufact IS NULL
            AND NOW() = NOW()
        LIMIT 1
    ";
	$q = $db->prepare($sql);
	$q->execute(['org_id' => $doc['org_id_client'], 'mat_id' => $mat['id']]);
	$r = $q->fetch();

	if ($r === false) {
        // Скидка по производителю
		$sql = "
            SELECT SQL_NO_CACHE cp.price_type_id, cp.discount
            FROM client_price cp
            WHERE cp.org_id = :org_id
                AND cp.org_id_manufact = :manufact_id
                AND cp.mat_id IS NULL
                AND NOW() = NOW()
            LIMIT 1
        ";
		$q = $db->prepare($sql);
		$q->execute(['org_id' => $doc['org_id_client'], 'manufact_id' => $mat['manufact_id']]);
		$r = $q->fetch();

		if ($r === false) {
            // Общая скидка
			$sql = "
                SELECT SQL_NO_CACHE cp.price_type_id, cp.discount
                FROM client_price cp
                WHERE cp.org_id = :org_id
                    AND cp.mat_id IS NULL
                    AND cp.org_id_manufact IS NULL
                    AND NOW() = NOW()
                LIMIT 1
            ";
			$q = $db->prepare($sql);
			$q->execute(['org_id' => $doc['org_id_client']]);
			$r = $q->fetch();

			if ($r === false) {
				$r = ['price_type_id' => null, 'discount' => 0];
			}
		}
	}


	$client_price_type_id = $r['price_type_id'];
	$client_price_discount = $r['discount'];
	//exit($doc['org_id_client'].' - '.$client_price_type_id.' - '.$client_price_discount);
	// Определяем цену
	$sql = "
        SELECT SQL_NO_CACHE p.price, p.date, p.currency_id
        FROM prices p
        WHERE p.price_type_id = :client_price_type_id
            AND p.tmc_id = :mat_id
            AND p.date <= :date
            AND NOW() = NOW()
        ORDER BY p.date DESC
        LIMIT 1
    ";
	$q = $db->prepare($sql);
	$q->execute(['client_price_type_id' => $client_price_type_id, 'mat_id' => $mat['id'], 'date' => $doc['date']]);
	$r = $q->fetch();
	if ($r === false) {
		return null;
	}

	$price = (100 - ($client_price_discount?$client_price_discount:0)) * $r['price'] / 100;
	// Если Валюта не Рубль, то пересчитаем по курсу на дату
	if ($r['currency_id'] != 1) {
		$sql = "
            SELECT SQL_NO_CACHE value
            FROM exchange_rate r
            WHERE :currency_id = r.currency_id
                AND r.date <= :date
                AND NOW() = NOW()
            ORDER BY r.date DESC
            LIMIT 1
        ";
		$q = $db->prepare($sql);
		$q->execute(['currency_id' => $r['currency_id'], 'date' => $doc['date']]);
		//$r = $q->fetch(); $value = $r['value'];
		$value = $q->fetchColumn();
		$price = $price * $value;
	}

    $memcache->set($key, $price, false, 10);

	return $price;
}

$data = [];
foreach ($materials as $material) {
	$price = get_client_price($material, $doc, $memcache);
	$data[] = ['name' => $material['name'].' - '.$price.' - '.$material['kol']];
}
$return = [];
$return['total_count'] = ($page - 1) * $per_page + (sizeof($data) < $per_page?sizeof($data):($per_page+1));
$return['items'] = $data;
$return['time'] = sprintf("%.6f", microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]);
echo json_encode($return);
