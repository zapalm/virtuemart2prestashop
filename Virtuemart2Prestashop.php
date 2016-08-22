<?php
/**
 * Скрипт миграции данных с Joomla VirtueMart на PrestaShop.
 * Принцип работы: выгружаются данные из базы Joomla и сохраняются в CSV-файлы, которые затем, в ручную, импортируются в PrestaShop через стандартный инструмент импорта.
 *
 * ВНИМАНИЕ: запускать только на локальном вебсервере, т.к. скрипт до конца не отлажен (и вообще устарел), имеет множество нюансов (см. метки TODO).
 *
 * Инструкция:
 * 1) Разместить скрипт в корне директории CMS PrestaShop.
 * 2) Директорию с Joomla CMS разместить также в корне PrestaShop, т.е.:
 *     /Virtuemart2Prestashop.php
 *     /joomla
 * 3) Указать в запуске только те методы по переносу данных, которые вам нужны (см. конец файла).
 * 4) Перейти по Url для запуска скрипта, например: http://prestashop.localhost/Virtuemart2Prestashop.php
 */

// конфиг prestashop
include(__DIR__ . '/config/config.inc.php');

/**
 * @version   0.1 (2011-01-19)
 * @author    zapalm <zapalm@ya.ru>
 * @link      http://prestashop.modulez.ru Модули для PrestaShop CMS
 * @license   GNU General Public License v3.0
 */
class Virtuemart2Prestashop
{
    private $field_sep = '|';
    private $multi_val_sep = '`';
    private $joomla_dir;
    private $joomla_web_url;
    private $cat_img_dir_local;
    private $cat_img_dir;
    private $csv_pathname;
    private $joomla_db_connection;
    private $prestashop_db_connection;
    private $dbs_names;
    private $dbs;
    private $current_db;
    private $csv_line_limit = 8;
    private $csv_line_counter = 0;
    private $descr;
    private $weight;
    private $users;
    private $fv_last_id;

    public function __construct(
        $joomla_web_url,
        $joomla_db_host = 'localhost',
        $joomla_db_name = '',
        $joomla_db_user = 'root',
        $joomla_db_password = '',
        $prestashop_db_host = 'localhost',
        $prestashop_db_name = '',
        $prestashop_db_user = 'root',
        $prestashop_db_password = ''
    ) {
        $this->joomla_db_connection = mysql_connect($joomla_db_host, $joomla_db_user, $joomla_db_password) or die("Could not connect to Joomla DB: " . mysql_error());
        $this->prestashop_db_connection = mysql_connect($prestashop_db_host, $prestashop_db_user, $prestashop_db_password) or die("Could not connect to PrestaShop DB: " . mysql_error());
        $this->dbs = array(0 => array($joomla_db_name => &$this->joomla_db_connection), 1 => array($prestashop_db_name => &$this->prestashop_db_connection));
        $this->dbs_names = array($joomla_db_name, $prestashop_db_name);
        $this->joomla_web_url = $joomla_web_url;
        $this->joomla_dir = dirname(__FILE__) . '/joomla';
        $this->cat_img_dir_local = $this->joomla_dir . '/components/com_virtuemart/shop_image/';
        $this->csv_pathname = $this->joomla_dir . '/tmp/';
        $this->cat_img_dir = $this->joomla_web_url . '/components/com_virtuemart/shop_image/';

        $this->switchToJoomlaDb();
    }

    public function __destruct()
    {
        mysql_close($this->prestashop_db_connection);
        mysql_close($this->joomla_db_connection);
    }

    protected function genPassword($length = 8)
    {
        $str = 'abcdefghijkmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for ($i = 0, $passwd = ''; $i < $length; $i++) {
            $passwd .= substr($str, mt_rand(0, strlen($str) - 1), 1);
        }
        return $passwd;
    }

    private function switchToJoomlaDb()
    {
        $this->current_db = 0;
        $this->changeDb();
    }

    protected function switchToPrestashopDb()
    {
        $this->current_db = 1;
        $this->changeDb();
    }

    private function changeDb()
    {
        mysql_select_db(
            $this->dbs_names[$this->current_db],
            $this->dbs[$this->current_db][$this->dbs_names[$this->current_db]]
        ) or die ('Can\'t use db : ' . mysql_error());
    }

    private function truncate($str, $size = 64)
    {
        if (strlen($str) > $size) {
            $str = substr($str, 0, $size - 3) . '...';
        }

        return $str;
    }

    private function makeCsvLine($line_in)
    {
        $line_out = '';

        foreach ($line_in as $k => $line) {
            $line_out .= $line . $this->field_sep;
        }

        return $line_out . "\n";
    }

    private function removeEndChars($string)
    {
        return str_replace(array("\r\n", "\r", "\n"), '', $string);
    }

    private function removeSpaces($string)
    {
        return ereg_replace(' ', '%20', $string);
    }

    public function exportCategories()
    {
        $sql = '
            SELECT
                `jos_vm_category`.`category_id` as `id`,
                `jos_vm_category`.`category_publish` as `active`,
                `jos_vm_category`.`category_name` as `name`,
                `jos_vm_category_xref`.`category_parent_id` as `parent_category`,
                `jos_vm_category`.`category_description` as `description`,
                `jos_vm_category`.`category_full_image` as `image_url`
            FROM `jos_vm_category`, `jos_vm_category_xref`
            WHERE `jos_vm_category`.`category_id`=`jos_vm_category_xref`.`category_child_id`
		';

        $this->switchToJoomlaDb();
        $result = mysql_query($sql, $this->joomla_db_connection) or die("Invalid query: " . mysql_error());
        $csv = '';
        while ($row = mysql_fetch_assoc($result)) {
            $line = '';
            $first = true;
            foreach ($row as $k => $v) {
                $id = null;
                if ($k == 'id') {
                    $id = $v;
                }
                $v = $k == 'active' ? ($v == 'Y' ? 1 : 0) : $v;
                $v = $k == 'name' ? $this->truncate(trim($this->removeSpecialChars(stripcslashes(strip_tags($this->removeEndChars($v)))))) : $v;
                $v = $k == 'parent_category' ? ($v == 0 ? 1 : $v) : $v;
                $v = $k == 'image_url' ? $v ? $this->removeSpaces($this->cat_img_dir) . 'category/' . $v : '' : $v;
                if ($k == 'description' && $id) {
                    $this->descr[$id] = trim(stripcslashes($this->removeSpecialChars(strip_tags($this->removeEndChars($v)))));
                    $v = '';
                }
                $line .= ($first ? '' : $this->field_sep) . $v;
                $first = false;
                $line .= $k == 'description' ? $this->field_sep . $this->field_sep . $this->field_sep . $this->field_sep : '';
            }
            $csv .= $line . "\r\n";
        }

        file_put_contents($this->csv_pathname . 'categories.csv', $csv);
        mysql_free_result($result);
    }

    // дописывает описание категорий
    public function updateCatsDescription()
    {
        $this->switchToPrestashopDb();
        foreach ($this->descr as $k => $v) {
            $result = mysql_query('UPDATE `ps_category_lang` SET `description`="' . trim(addslashes(strip_tags($this->removeEndChars($v)))) . '" WHERE `id_category`=' . $k) or die("Invalid query: " . mysql_error());
        }
    }

    public function exportManufacturers()
    {
        $sql = '
            SELECT
                `jos_vm_manufacturer`.`manufacturer_id` as `id`,
                `jos_vm_manufacturer`.`mf_name` as `name`,
                `jos_vm_manufacturer`.`mf_desc` as `description`
            FROM `jos_vm_manufacturer`
		';

        $this->switchToJoomlaDb();
        $result = mysql_query($sql, $this->joomla_db_connection) or die("Invalid query: " . mysql_error());
        $csv = '';
        while ($row = mysql_fetch_assoc($result)) {
            $first = true;
            $line = '';
            foreach ($row as $k => $v) {
                $v = $k == 'name' ? $this->truncate(trim($this->removeSpecialChars(stripcslashes(strip_tags($this->removeEndChars($v)))))) : $v;
                $v = $k == 'description' ? trim(stripcslashes($this->removeSpecialChars(strip_tags($this->removeEndChars($v))))) : $v;
                $line .= ($first ? '' : $this->field_sep) . $v;
                $first = false;
            }
            $csv .= $line . "\r\n";
        }

        file_put_contents($this->csv_pathname . 'manufacturers.csv', $csv);
        mysql_free_result($result);
    }

    public function exportProducts()
    {
        $data = array(
            'id' => '',
            'active' => 1,
            'name' => '',
            'categories' => '',
            'price_tax_exclude' => '', // или 'price_tax_include' => '',
            'tax_rate' => '',
            'wholesale_price' => '',
            'onsale' => 0,
            'reduction_amount' => '',
            'reduction_percent' => '',
            'reduction_from' => '2011-01-3',
            'reduction_to' => '2011-01-3',
            'reference' => '',
            'supplier_reference' => '',
            'supplier' => '',
            'manufacturer' => '',
            'ean13' => '',
            'ecotax' => '',
            'weight' => '',
            'quantity' => 100,
            'short_description' => '',
            'description' => '',
            'tags' => '',
            'meta_title' => '',
            'meta_keywords' => '',
            'meta_description' => '',
            'url_rewrited' => '',
            'text_when_instock' => '',
            'text_if_backorder_allowed' => '',
            'image_urls' => '',
            'feature' => '',
        );

        $sql = '
            SELECT
                p.`product_id` as `id`,
                p.`product_publish` as `active`,
                p.`product_name` as `name`,
                p.`product_sku` as `reference`,
                p.`product_weight` as `weight`,
                p.`product_in_stock` as `quantity`,
                p.`product_s_desc` as `short_description`,
                p.`product_desc` as `description`,
                p.`product_full_image` as `image_urls`,
                p.`product_tax_id`,
                p.`product_discount_id`,
                mf.`mf_name` as `manufacturer`,
                t.`tax_rate`,
                pp.`product_price` as `price_tax_exclude`,
                d.`amount` as `reduction_amount`,
                d. `is_percent`,
                d.`start_date` as `reduction_from`,
                d.`end_date` as `reduction_to`
            FROM `jos_vm_product` p
            LEFT JOIN `jos_vm_product_mf_xref` pm ON pm.`product_id`=p.`product_id`
            LEFT JOIN `jos_vm_manufacturer` mf ON mf.`manufacturer_id`=pm.`manufacturer_id`
            LEFT JOIN `jos_vm_tax_rate` t ON t.`tax_rate_id`=p.`product_tax_id`
            LEFT JOIN `jos_vm_product_price` pp ON pp.`product_id`=p.`product_id`
            LEFT JOIN  `jos_vm_product_discount` d ON d.`discount_id`=p.`product_discount_id`
		';

        $this->switchToJoomlaDb();
        $result = mysql_query($sql, $this->joomla_db_connection) or die("Invalid query: " . mysql_error());
        $csv = '';
        while ($row = mysql_fetch_assoc($result)) {
            foreach ($row as $k => $v) {
                $v = $k == 'active' ? ($v == 'Y' ? 1 : 0) : $v;
                $v = $k == 'name' ? $this->truncate(trim($this->removeSpecialChars(stripcslashes(strip_tags($this->removeEndChars($v)))))) : $v;

                if ($k == 'description') {
                    $this->descr[$data['id']] = trim(stripcslashes($this->removeSpecialChars($this->removeEndChars($v))));
                    $v = '';
                }

                $v = $k == 'short_description'
                    ? $this->truncate(trim(stripcslashes($this->removeSpecialChars(strip_tags($this->removeEndChars($v))))), 400)
                    : $v
                ;

                $v = $k == 'quantity' ? ($v < 0 ? 0 : $v) : $v;
                $v = $k == 'image_urls' ? $this->removeSpaces($this->cat_img_dir) . 'product/' . $v : $v;
                if ($k == 'weight') {
                    $this->weight[$data['id']] = floatval($v);
                    $v = 0;
                }

                if ($k == 'is_percent') {
                    $data['reduction_percent'] = $data['reduction_amount'];
                    $data['reduction_amount'] = 0;
                }

                if (isset($data[$k])) {
                    $data[$k] = $v;
                }
            }

            // категории товара
            $sql = '
                SELECT
                    pc.`category_id`,
                    pc.`product_id`,
                    c.`category_name`
				FROM `jos_vm_product_category_xref` pc
				LEFT JOIN `jos_vm_category` c ON c.`category_id`=pc.`category_id`
				WHERE pc.`product_id`=' . $data['id']
            ;

            $cats = mysql_query($sql, $this->joomla_db_connection) or die("Invalid query: " . mysql_error());
            $first = true;

            $c = '';
            while ($row = mysql_fetch_assoc($cats)) {
                $c .= ($first ? '' : $this->multi_val_sep) . $this->truncate(stripcslashes(strip_tags($this->removeEndChars($row['category_name']))));
                $first = false;
            }

            mysql_free_result($cats);
            $data['categories'] = $c;

            // картинки товара
            $sql = 'SELECT `file_name` as `image_path` FROM `jos_vm_product_files` WHERE `file_product_id`=' . $data['id'];

            $imgs = mysql_query($sql, $this->joomla_db_connection) or die("Invalid query: " . mysql_error());

            $first = $data['image_urls'] ? false : true;

            $c = '';
            while ($row = mysql_fetch_assoc($imgs)) {
                $c .= ($first ? '' : $this->multi_val_sep) . $this->joomla_web_url . $this->removeSpaces($row['image_path']);
                $first = false;
            }

            mysql_free_result($imgs);
            $data['image_urls'] .= $c;

            $csv .= $this->makeCsvLine($data);
            $this->csv_line_counter++;
            if ($this->csv_line_counter == $this->csv_line_limit) {
                file_put_contents($this->csv_pathname . 'products-' . $data['id'] . '.csv', $csv);
                $csv = '';
                $this->csv_line_counter = 0;
            }

        }

        mysql_free_result($result);
        file_put_contents($this->csv_pathname . 'products-end.csv', $csv);
    }

    // дописывает описание
    public function updateProductDescription()
    {
        $this->switchToPrestashopDb();
        foreach ($this->descr as $k => $v) {
            $result = mysql_query('UPDATE `ps_product_lang` SET `description`="' . trim(addslashes($this->removeEndChars($v))) . '" WHERE `id_product`=' . $k) or die("Invalid query: " . trim(stripcslashes(($this->removeEndChars($v)))));
        }
    }

    // дописывает вес
    public function updateProductWeight()
    {
        $this->switchToPrestashopDb();
        foreach ($this->weight as $k => $v) {
            $result = mysql_query('UPDATE `ps_product` SET `weight`=' . $v . ' WHERE `id_product`=' . $k) or die("Invalid query: " . trim(stripcslashes(($this->removeEndChars($v)))));
        }
    }

   public function exportComments($guest_customer_id)
    {
        $sql = '
            SELECT
                `review_id` as `id_product_comment`,
                `product_id` as `id_product`,
                `comment` as `content`,
                `userid` as `id_customer`,
                `time` as `date_add`,
                `user_rating` as `grade`,
                `published` as `validate`
            FROM `jos_vm_product_reviews`
		';

        $this->switchToJoomlaDb();
        $comms = mysql_query($sql, $this->joomla_db_connection) or die("Invalid query: " . mysql_error());
        $this->switchToPrestashopDb();
        while ($row = mysql_fetch_assoc($comms)) {
            mysql_query('INSERT INTO `ps_product_comment`
					(
						`id_product_comment`,
						`id_product`,
						`id_customer`,
						`content`,
						`grade`,
						`validate`,
						`date_add`
					)
					VALUES
					(
						NULL,
						' . $row['id_product'] . ',
						' . ($guest_customer_id ? $guest_customer_id : $row['id_customer']) . ',
						"' . $row['content'] . '",
						' . floatval($row['grade']) . ',
						' . ($row['validate'] == 'Y' ? 1 : 0) . ',
						"' . date("Y-m-d H:i:s", $row['date_add']) . '"
					)', $this->prestashop_db_connection);
        }

        mysql_free_result($comms);
    }

    private function removeSpecialChars($str)
    {
        $specials = array(
            '«' => '"',
            '»' => '"',
            '–' => '-',
        );

        $nn = array_values($specials);
        $sp = array_keys($specials);

        return str_replace($sp, $nn, $str);
    }

    public function exportCustomers()
    {
        $sql = '
            SELECT
                u.`id` as `id_customer`,
                u.`block` as `active`,
                ui.`user_email` as `email`,
                ui.`last_name` as `lastname`,
                ui.`first_name` as `firstname`,
                u.`sendEmail` as `newsletter`
			FROM `jos_users` u, `jos_vm_user_info` ui
			WHERE u.`id`=ui.`user_id` AND (u.`usertype` NOT IN("Super Administrator"))
		';

        $data = array(
            'id_customer' => '',
            'active' => 0,
            'id_gender' => 9,
            'email' => '',
            'passwd' => '',
            'birthday' => '',
            'lastname' => '',
            'firstname' => '',
            'newsletter' => 0,
            'optin' => 0
        );

        $this->switchToJoomlaDb();
        $res = mysql_query($sql, $this->joomla_db_connection) or die("Invalid query: " . mysql_error());
        $csv = '';
        while ($row = mysql_fetch_assoc($res)) {
            if (!Validate::isEmail($row['email'])) {
                continue;
            }

            $row['id_gender'] = 9;
            $row['passwd'] = $this->genPassword();
            $row['birthday'] = '';
            $row['optin'] = '';
            $this->users[$row['id_customer']] = $row;
            foreach ($row as $k => $v) {
                $v = $k == 'id_customer' ? '' : $v; // TODO: закоментировать если будут импортироваться отзывы
                $v = $k == 'active' ? $v['active'] ? 0 : 1 : $v;
                $v = $k == 'firstname' ? $v ? $v : 'NO' : $v;
                $v = $k == 'lastname' ? $v ? $v : 'NO' : $v;

                $data[$k] = $v;
            }

            $csv .= $this->makeCsvLine($data);
        }

        file_put_contents($this->csv_pathname . 'users.csv', $csv);
        mysql_free_result($res);
    }

    public function sendCustomersEmail($id_lang)
    {
        global $smarty;

        foreach ($this->users as $k => $c) {
            Mail::Send(intval($id_lang), 'password', 'Your password',
                array(
                    '{email}' => $c['email'],
                    '{lastname}' => $c['lastname'],
                    '{firstname}' => $c['firstname'],
                    '{passwd}' => $c['passwd']
                ),
                $c['email'],
                $c['firstname'] . ' ' . $c['lastname']
            );

            $smarty->assign(array('confirmation' => 1, 'email' => $c['email']));
        }
    }

    public function exportAdresses()
    {
        $sql = '
            SELECT
                ui.`company`,
                ui.`title` as `alias`,
                ui.`last_name` as `lastname`,
                ui.`first_name` as `firstname`,
                ui.`phone_1` as `phone_mobile`,
                ui.`phone_2` as `phone`,
                ui.`address_1` as `address1`,
                ui.`address_2` `address2`,
                ui.`city`,
                ui.`zip` as `postcode`,
                ui.`user_email` as `email`,
                ui.`extra_field_1` as `other`,
                c.`country_name` as `country`,
                s.`state_name` as `state`
            FROM `jos_vm_user_info` ui
            LEFT JOIN `jos_vm_state` s ON s.`state_id`=ui.`state`
            LEFT JOIN  `jos_vm_country` c ON (c.`country_2_code`=ui.`country` OR c.`country_3_code`=ui.`country`)
		';

        $data = array(
            'id_address' => '',
            'alias' => 'Home address',
            'active' => 1,
            'email' => '',
            'manufacturer' => '',
            'supplier' => '',
            'company ' => '',
            'lastname' => '',
            'firstname' => '',
            'address1' => '',
            'address2' => '',
            'postcode' => '',
            'city' => '',
            'country' => '',
            'state' => '',
            'other' => '',
            'phone' => '',
            'phone_mobile' => ''
        );

        $this->switchToJoomlaDb();
        $res = mysql_query($sql, $this->joomla_db_connection) or die("Invalid query: " . mysql_error());
        $csv = '';
        while ($row = mysql_fetch_assoc($res)) {
            if (!Validate::isEmail($row['email'])) {
                continue;
            }

            $row['alias'] = 'Home address';
            $row['state'] = '';
            foreach ($row as $k => $v) {
                $v = $k == 'id_address' ? '' : $v;
                $v = $k == 'firstname' ? $v ? $v : 'NO' : $v;
                $v = $k == 'lastname' ? $v ? $v : 'NO' : $v;

                if ($k == 'address1' && (!Validate::isAddress($v) || !$v)) {
                    $v = 'NO';
                }

                if ($k == 'postcode' && (!Validate::isPostCode($v) || !$v)) {
                    $v = '000000';
                }

                if ($k == 'city' && (!Validate::isCityName($v) || !$v)) {
                    $v = 'NO';
                }

                if ($k == 'country' && (!Validate::isCountryName($v) || !$v)) {
                    $v = 'NO';
                }

                $data[$k] = $v;
            }

            $csv .= $this->makeCsvLine($data);
        }

        file_put_contents($this->csv_pathname . 'addresses.csv', $csv);
        mysql_free_result($res);
    }

    public function exportSizes()
    {
        // TODO: сверить с айдишниками в бд перед запуском
        $features = array(
            3 => 'height',
            6 => 'width',
            2 => 'depth',
            4 => 'weight',
        );

        $sql = 'SELECT `id_lang` FROM `ps_lang`;';
        $this->switchToPrestashopDb();
        $langs = mysql_query($sql, $this->prestashop_db_connection) or die("Invalid query: " . mysql_error());
        $l = array();
        while ($lang_row = mysql_fetch_array($langs)) {
            $l[] = $lang_row[0];
        }
        mysql_free_result($langs);

        $sql = 'SELECT fv.`id_feature_value` FROM `ps_feature_value` fv ORDER BY fv.`id_feature_value` desc';
        $fv_ids = mysql_query($sql, $this->prestashop_db_connection) or die("Invalid query: " . mysql_error());
        if (mysql_num_rows($fv_ids)) {
            $this->fv_last_id = mysql_result($fv_ids, 0);
        } else {
            $this->fv_last_id = 1;
        }
        mysql_free_result($fv_ids);

        $sql = '
            SELECT
                p.`product_id` as `id_product`,
                p.`product_weight` as `weight`,
                p.`product_length` as `depth`,
                p.`product_width` as `width`,
                p.`product_height` as `height`
			FROM `jos_vm_product` p
		';

        $this->switchToJoomlaDb();
        $res = mysql_query($sql, $this->joomla_db_connection) or die("Invalid query: " . mysql_error());
        $this->switchToPrestashopDb();
        while ($row = mysql_fetch_assoc($res)) {
            foreach ($features as $k => $v) {
                ++$this->fv_last_id;

                mysql_query('
				    INSERT INTO `ps_feature_value`
					(
						`id_feature_value`,
						`id_feature`,
						`custom`
					)
					VALUES
					(
						' . $this->fv_last_id . ',' . $k . ',
						1
					)', $this->prestashop_db_connection) or die("Invalid query: " . mysql_error());

                foreach ($l as $key => $lang_id) {

                    mysql_query('
					    INSERT INTO `ps_feature_value_lang`
						(
							`id_feature_value`,
							`id_lang`,
							`value`
						)
						VALUES
						(
							' . $this->fv_last_id . ',
							' . $lang_id . ',
							' . number_format($row[$v], 2, '.', '') . '
						)', $this->prestashop_db_connection) or die("Invalid query: " . mysql_error());

                }

                mysql_query('
				INSERT INTO `ps_feature_product`
					(
						`id_feature`,
						`id_product`,
						`id_feature_value`
					)
					VALUES
					(
						' . $k . ',
						' . $row['id_product'] . ',
						' . $this->fv_last_id . '
					)', $this->prestashop_db_connection) or die("Invalid query: " . mysql_error());

            }
        }

        mysql_free_result($res);
    }

}

//TODO: подставить параметры
$processor = new Virtuemart2Prestashop(
    'http://joomla.localhost',
    'localhost',
    'joomla_db_name',
    'root',
    'joomla_db_password',
    'localhost',
    'prestashop_db_name',
    'root',
    'prestashop_db_password'
);

// TODO: закомментировать ненужные вызовы методов (если какие-то данные не нужно переносить)
$processor->exportCategories();
$processor->updateCatsDescription();
$processor->exportManufacturers();
$processor->exportProducts();
$processor->updateProductWeight();
$processor->updateProductDescription();
$processor->exportCustomers();

$processor->exportComments(2);      // TODO: указать id гостевого пользователя; модуль комментариев к товарам в prestashop должен быть установлен
$processor->sendCustomersEmail(1);  // TODO: указать id языка письма для рассылки новых паролей
$processor->exportAdresses();
$processor->exportSizes();
