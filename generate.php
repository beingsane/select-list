<?php
require_once 'config.php';

$config = new Config();
$db = new PDO("mysql:host=" . $config->db_host.";dbname=".$config->db_name, $config->db_username, $config->db_password);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('SET names utf8');
$db->exec('SET storage_engine=innoDB');
$db->exec("SET sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION,PIPES_AS_CONCAT,ONLY_FULL_GROUP_BY'");

//$cn = isset($_REQUEST['cn'])?$_REQUEST['cn'] * 1:10000;
$material_cn = 10000;
$client_cn = 1000;

$db->exec("SET FOREIGN_KEY_CHECKS=0");
// Документы
$db->exec("DROP TABLE IF EXISTS `docs`");
$db->exec("CREATE TABLE `docs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `org_id_addr` int(11) NOT NULL, -- Склад
  `org_id_client` int(11) NOT NULL,
  PRIMARY KEY (`id`)
)");
$db->exec("INSERT `docs` (`id`, `date`, `org_id_addr`, `org_id_client`) VALUES (20515, '2016-10-12', 1, 500)");

// Материалы
$db->exec("DROP TABLE IF EXISTS `materials`");
$db->exec("CREATE TABLE `materials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `manufact_id` int(11) NOT NULL, -- Производитель
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
)");
$q = $db->prepare("INSERT materials (name, manufact_id) VALUES (CONCAT('Материал товар №', LPAD(:name, 5, '0')), :manufact_id)");
for ($i = 1; $i <= $material_cn; $i++) {
	$q->execute(['name' => $i, 'manufact_id' => (($i % 10) + 1)]);
}

// Остатки
$db->exec("DROP TABLE IF EXISTS `cur_stock`");
$db->exec("CREATE TABLE `cur_stock` (
  `mat_id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL, -- Склад
  `kol` decimal(10,3) NOT NULL,
  PRIMARY KEY (`org_id`,`mat_id`),
  KEY `mat_id` (`mat_id`),
  CONSTRAINT `cur_stock_ibfk_1` FOREIGN KEY (`mat_id`) REFERENCES `materials` (`id`)
)");
//echo PHP_EOL.'<br>INSERT INTO cur_stock = '.
$db->exec("INSERT INTO cur_stock (mat_id, org_id, kol)
SELECT m.id mat_id, o.org_id, FLOOR(RAND() * 500) kol
FROM
(SELECT id FROM materials ORDER BY RAND() LIMIT ".floor($material_cn / 1.5).") m
, (SELECT 1 org_id UNION ALL SELECT 2 UNION ALL SELECT 3) o");


// Прайсы клиентов
$db->exec("DROP TABLE IF EXISTS `client_price`");
$db->exec("CREATE TABLE `client_price` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL, -- Клиент
  `price_type_id` int(11) NOT NULL, -- Тип прайса
  `org_id_manufact` int(11) DEFAULT NULL, -- Производитель
  `mat_id` int(11) DEFAULT NULL, -- Материал
  `discount` decimal(5,2) DEFAULT NULL, -- Скидка
  PRIMARY KEY (`id`),
  UNIQUE KEY `org_id` (`org_id`,`price_type_id`,`org_id_manufact`,`mat_id`,`discount`),
  KEY `price_type_id` (`price_type_id`),
  KEY `mat_id` (`mat_id`),
  KEY `org_id_manufact` (`org_id_manufact`),
  CONSTRAINT `client_price_ibfk_1` FOREIGN KEY (`mat_id`) REFERENCES `materials` (`id`)
)");
// Общие прайсы
$q = $db->prepare("INSERT client_price (org_id, price_type_id) VALUES (:org_id, :price_type_id)");
for ($i = 1; $i <= $client_cn; $i++) {
	$q->execute(['org_id' => $i, 'price_type_id' => (($i % 2) + 1)]);
}
// Скидки по производителю
$db->exec("INSERT INTO client_price (org_id, price_type_id, org_id_manufact)
SELECT o.org_id
, FLOOR(1 + RAND() * 3) price_type_id
, m.manufact_id
FROM
(SELECT DISTINCT manufact_id FROM materials) m
, (SELECT DISTINCT org_id FROM client_price ORDER BY CASE WHEN org_id = 500 THEN 1 ELSE 2 END, RAND() LIMIT ".floor($client_cn / 10).") o
ORDER BY RAND()
LIMIT ".floor($client_cn / 2));
// Скидки по материалам
$db->exec("INSERT INTO client_price (org_id, price_type_id, mat_id)
SELECT o.org_id
, FLOOR(1 + RAND() * 3) price_type_id
, m.id
FROM
(SELECT id FROM materials ORDER BY RAND() LIMIT ".floor($material_cn / 10).") m
, (SELECT DISTINCT org_id FROM client_price ORDER BY CASE WHEN org_id = 500 THEN 1 ELSE 2 END, RAND() LIMIT ".floor($client_cn / 10).") o
ORDER BY RAND()
LIMIT ".(floor($client_cn / 10) * floor($material_cn / 10) / 10));

// Курсы валют
$db->exec("DROP TABLE IF EXISTS `exchange_rate`");
$db->exec("CREATE TABLE IF NOT EXISTS `exchange_rate` (
  `currency_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `value` decimal(29,15) NOT NULL,
  PRIMARY KEY (`currency_id`,`date`)
)");
$q = $db->prepare("INSERT exchange_rate (currency_id, date, value) VALUES (:currency_id, DATE(NOW()) - INTERVAL :i DAY, :value)");
for ($i = 100; $i >= 0; $i--) {
	$q->execute(['currency_id' => 2, 'i' => ($i + 1), 'value' => 100 - ($i / 100)]);
}

// Прайсы
$db->exec("DROP TABLE IF EXISTS prices");
$db->exec("CREATE TABLE `prices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `price_type_id` int(11) NOT NULL,
  `tmc_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `price` decimal(29,15) NOT NULL,
  `currency_id` int(11) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `tmc_id` (`tmc_id`,`price_type_id`,`date`),
  KEY `price_type_id` (`price_type_id`),
  CONSTRAINT `prices_ibfk_2` FOREIGN KEY (`tmc_id`) REFERENCES `materials` (`id`)
)");
$db->exec("INSERT INTO prices (price_type_id, tmc_id, date, price, currency_id)
SELECT pt.price_type_id, m.id
, d.date
, FLOOR(RAND() * 1000) price
, FLOOR(1 + RAND() * 2) currency_id
FROM materials m
, (SELECT DATE(NOW()) - INTERVAL dd.id MONTH AS date FROM materials dd ORDER BY id LIMIT 10) d -- Даты
, (SELECT 1 price_type_id UNION ALL SELECT 2 UNION ALL SELECT 3) pt -- Типы прайсов
");

// Функция get_price
$db->exec("DROP FUNCTION IF EXISTS get_client_price");
$db->exec("CREATE FUNCTION get_client_price(v_org_id INT, v_mat_id INT, v_date DATE)
  RETURNS decimal(29,15) READS SQL DATA
BEGIN
  /* версия 00002 */
  DECLARE v_price_date date;
  DECLARE v_price decimal(29,15);
  DECLARE EXIT HANDLER FOR NOT FOUND BEGIN
    RETURN NULL;
  END;
  SET @client_price_type_id := NULL;
  SET @client_price_discount := NULL;
  SET @client_price_date := NULL;
  -- Определяем тип прайса
  SELECT price_type_id, discount
   INTO @client_price_type_id, @client_price_discount
   FROM (
   SELECT cp.price_type_id, cp.discount, 10 rule
   FROM client_price cp
   WHERE cp.org_id = v_org_id
    AND cp.mat_id = v_mat_id
    AND cp.org_id_manufact IS NULL
   UNION ALL
   SELECT cp.price_type_id, cp.discount, 50 rule
   FROM client_price cp
   INNER JOIN materials m ON cp.org_id_manufact = m.manufact_id
   WHERE cp.org_id = v_org_id
    AND m.id = v_mat_id
    AND cp.mat_id IS NULL
   UNION ALL
   SELECT cp.price_type_id, cp.discount, 100 rule
   FROM client_price cp
   WHERE cp.org_id = v_org_id
    AND cp.mat_id IS NULL
    AND cp.org_id_manufact IS NULL
   ) t
   ORDER BY rule
   LIMIT 1;
  -- Определяем цену
  SELECT p.price
  * CASE WHEN p.currency_id = 1 THEN 1
    ELSE (SELECT value FROM exchange_rate r WHERE p.currency_id = r.currency_id AND r.date <= v_date ORDER BY r.date DESC LIMIT 1)
   END
  * (100 - IFNULL(@client_price_discount, 0)) / 100 price
  , p.date
   INTO v_price, @client_price_date
   FROM prices p
   WHERE p.price_type_id = @client_price_type_id
    AND p.tmc_id = v_mat_id
    AND p.date <= v_date
  ORDER BY p.date DESC
  LIMIT 1;

  RETURN v_price;
END;
");

$db->exec("SET FOREIGN_KEY_CHECKS=1");


echo sprintf("%.6f", microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]);
