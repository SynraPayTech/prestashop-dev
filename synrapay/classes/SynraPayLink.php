<?php
/**
 * The link between a PrestaShop cart (and later its order) and the SynraPay
 * Direct order its card fields were opened on. The Direct order is created
 * before the PrestaShop order exists (validateOrder runs only once SynraPay
 * says paid), so the link cannot live on the order.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SynraPayLink
{
    const TABLE = 'synrapay_cart';

    /** Columns added after 1.0.1: [name => definition]. Added by installTable() and upgrade-1.0.2. */
    const EXTRA_COLUMNS = [
        'txn_id'    => 'VARCHAR(64) NOT NULL DEFAULT "" AFTER `gateway_ref`',
        'auth_code' => 'VARCHAR(32) NOT NULL DEFAULT "" AFTER `txn_id`',
    ];

    /** @var array|null the table's column names, read once per request */
    private static $columns = null;

    public static function installTable()
    {
        $ok = Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_cart` INT(10) UNSIGNED NOT NULL,
            `id_order` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `direct_order_id` VARCHAR(64) NOT NULL DEFAULT "",
            `merchant_order_id` VARCHAR(64) NOT NULL DEFAULT "",
            `amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `currency` VARCHAR(3) NOT NULL DEFAULT "",
            `fp` VARCHAR(32) NOT NULL DEFAULT "",
            `env` VARCHAR(16) NOT NULL DEFAULT "",
            `status` VARCHAR(32) NOT NULL DEFAULT "created",
            `gateway_ref` VARCHAR(128) NOT NULL DEFAULT "",
            `txn_id` VARCHAR(64) NOT NULL DEFAULT "",
            `auth_code` VARCHAR(32) NOT NULL DEFAULT "",
            `history` TEXT,
            `attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `checks` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `awaiting_since` DATETIME NULL DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_cart`),
            KEY `direct_order_id` (`direct_order_id`),
            KEY `merchant_order_id` (`merchant_order_id`),
            KEY `id_order` (`id_order`),
            KEY `status` (`status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
        return $ok && self::ensureColumns();
    }

    /** Adds the columns a table created by an older version lacks. Safe to run any number of times. */
    public static function ensureColumns()
    {
        self::$columns = null;
        foreach (self::EXTRA_COLUMNS as $name => $definition) {
            if (!self::hasColumn($name)) {
                if (!Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . self::TABLE . '` ADD COLUMN `' . $name . '` ' . $definition)) {
                    return false;
                }
                self::$columns = null;
            }
        }
        return true;
    }

    /** Whether the table has a column: until the upgrade has run, save() skips the newer ones. */
    public static function hasColumn($name)
    {
        if (self::$columns === null) {
            self::$columns = [];
            $rows = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . self::TABLE . '`');
            foreach (is_array($rows) ? $rows : [] as $row) {
                self::$columns[] = (string) $row['Field'];
            }
        }
        return in_array((string) $name, self::$columns, true);
    }

    public static function getByCart($id_cart)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_cart` = ' . (int) $id_cart);
        return $row ? $row : [];
    }

    public static function getByOrder($id_order)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_order` = ' . (int) $id_order);
        return $row ? $row : [];
    }

    /** By SynraPay's order_id or our merchant_order_id — the current one, or a superseded retry kept in history. */
    public static function getByRef($ref)
    {
        $ref = pSQL((string) $ref);
        if ($ref === '') {
            return [];
        }
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `direct_order_id` = "' . $ref . '" OR `merchant_order_id` = "' . $ref . '" ORDER BY `date_upd` DESC');
        if ($row) {
            return $row;
        }
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `history` LIKE "%' . $ref . '%" ORDER BY `date_upd` DESC');
        return $row ? $row : [];
    }

    public static function save($id_cart, array $data)
    {
        $id_cart = (int) $id_cart;
        $cols = [];
        foreach (['direct_order_id', 'merchant_order_id', 'currency', 'fp', 'env', 'status', 'txn_id', 'auth_code', 'history'] as $k) {
            // gateway_ref (before 1.0.2) stays in the table but is no longer written: it is SynraPay-internal.
            if (array_key_exists($k, $data) && (!isset(self::EXTRA_COLUMNS[$k]) || self::hasColumn($k))) {
                $cols[] = '`' . $k . '` = "' . pSQL((string) $data[$k]) . '"';
            }
        }
        if (array_key_exists('amount', $data)) {
            $cols[] = '`amount` = ' . (float) $data['amount'];
        }
        foreach (['attempts', 'checks', 'id_order'] as $k) {
            if (array_key_exists($k, $data)) {
                $cols[] = '`' . $k . '` = ' . (int) $data[$k];
            }
        }
        if (array_key_exists('awaiting_since', $data)) {
            $cols[] = '`awaiting_since` = ' . ($data['awaiting_since'] === null ? 'NULL' : '"' . pSQL((string) $data['awaiting_since']) . '"');
        }
        $cols[] = '`date_upd` = NOW()';
        if (self::getByCart($id_cart)) {
            return Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . self::TABLE . '` SET ' . implode(', ', $cols) . ' WHERE `id_cart` = ' . $id_cart);
        }
        return Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '` SET `id_cart` = ' . $id_cart . ', ' . implode(', ', $cols) . ', `date_add` = NOW()');
    }

    /** Orders left awaiting confirmation: between 1 minute and 3 hours old. */
    public static function getAwaiting()
    {
        $rows = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `status` = "pending_confirmation" AND `awaiting_since` IS NOT NULL AND `awaiting_since` < DATE_SUB(NOW(), INTERVAL 1 MINUTE) AND `awaiting_since` > DATE_SUB(NOW(), INTERVAL 3 HOUR) ORDER BY `awaiting_since` ASC LIMIT 50');
        return is_array($rows) ? $rows : [];
    }
}
