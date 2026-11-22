<?php
/**
 * Limpieza de la base de datos por apartados.
 *
 * Cada apartado dice qué tabla toca, con qué condición y qué necesita existir en
 * esa versión de PrestaShop. Nada se da por supuesto: antes de ofrecer un
 * apartado se comprueba en la base de datos que la tabla y las columnas están,
 * y el borrado siempre se hace por la clave primaria real de las filas que se
 * acaban de guardar en la copia previa, nunca por un patrón.
 *
 * Riesgos: 0 = seguro (lo puede hacer el automático), 1 = conviene revisarlo
 * antes, 2 = hay que confirmarlo a mano.
 *
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosralimpiezaDb
{
    /** @var array claves primarias reales por tabla */
    protected static $keys = array();

    /**
     * Clave primaria real de la tabla, leída de la base de datos.
     *
     * @param string $table nombre sin prefijo
     *
     * @return array columnas (vacío si la tabla no tiene clave primaria)
     */
    public static function primaryKey($table)
    {
        if (isset(self::$keys[$table])) {
            return self::$keys[$table];
        }
        self::$keys[$table] = array();
        if (!JosralimpiezaCore::tableExists($table)) {
            return self::$keys[$table];
        }
        $rows = Db::getInstance()->executeS(
            'SHOW KEYS FROM `' . _DB_PREFIX_ . bqSQL($table) . '` WHERE `Key_name` = \'PRIMARY\''
        );
        if (is_array($rows)) {
            $ordered = array();
            foreach ($rows as $row) {
                if (isset($row['Column_name'])) {
                    $ordered[(int) (isset($row['Seq_in_index']) ? $row['Seq_in_index'] : count($ordered) + 1)] = $row['Column_name'];
                }
            }
            ksort($ordered);
            self::$keys[$table] = array_values($ordered);
        }

        return self::$keys[$table];
    }

    /**
     * Todos los apartados, agrupados, ya resueltos con los ajustes y el esquema
     * real de esta tienda.
     */
    public static function groups()
    {
        $settings = JosralimpiezaCore::settings();
        $old = JosralimpiezaCore::cutoff($settings['ANTIGUEDAD_REGISTROS']);
        $oldCarts = JosralimpiezaCore::cutoff($settings['ANTIGUEDAD_CARRITOS']);
        $meses = (int) $settings['ANTIGUEDAD_REGISTROS'];
        $mesesCarritos = (int) $settings['ANTIGUEDAD_CARRITOS'];

        $groups = array();

        /* --- Registros y estadísticas ---------------------------------- */
        $groups['registros'] = array(
            'name' => 'Registros y estadísticas',
            'help' => 'Lo que la tienda apunta de cada visita y de cada aviso. No se usa para nada del negocio y suele ser lo que más pesa. '
                . 'Se borra lo anterior a ' . $meses . ' ' . ($meses === 1 ? 'mes' : 'meses') . '.',
            'items' => array(
                self::item('log', 'Registro de errores y avisos de la tienda', 0, '`t`.`date_add` < \'' . pSQL($old) . '\'', array('log.date_add')),
                self::item('connections_source', 'De dónde llegaron las visitas', 0, '`t`.`date_add` < \'' . pSQL($old) . '\'', array('connections_source.date_add')),
                self::item('connections_page', 'Páginas vistas en cada visita', 0, '`t`.`time_start` < \'' . pSQL($old) . '\'', array('connections_page.time_start')),
                self::item('connections', 'Visitas registradas', 0, '`t`.`date_add` < \'' . pSQL($old) . '\'', array('connections.date_add')),
                self::item('pagenotfound', 'Páginas no encontradas (error 404)', 0, '`t`.`date_add` < \'' . pSQL($old) . '\'', array('pagenotfound.date_add')),
                self::item('statssearch', 'Búsquedas de los visitantes', 0, '`t`.`date_add` < \'' . pSQL($old) . '\'', array('statssearch.date_add')),
            ),
        );

        /* --- Caché en base de datos ------------------------------------ */
        $groups['cache'] = array(
            'name' => 'Caché guardada en la base de datos',
            'help' => 'Caché de plantillas que PrestaShop vuelve a generar sola. Se vacía entera, no hace falta copia.',
            'items' => array(
                self::truncateItem('smarty_cache', 'Caché de plantillas', 0),
                self::truncateItem('smarty_lazy_cache', 'Índice de la caché de plantillas', 0),
            ),
        );

        /* --- Visitantes e invitados ------------------------------------ */
        $groups['visitas'] = array(
            'name' => 'Visitantes e invitados',
            'help' => 'Visitantes anónimos que no llegaron a comprar. Las cuentas con algún pedido no se tocan nunca, ni sus direcciones, '
                . 'y los mensajes de atención al cliente se quedan como están. El orden importa: primero las cuentas y después lo que cuelga de ellas.',
            'items' => array(
                self::item(
                    'customer',
                    'Cuentas de invitado sin ningún pedido',
                    1,
                    '`t`.`is_guest` = 1 AND `t`.`date_add` < \'' . pSQL($oldCarts) . '\' AND `o`.`id_order` IS NULL',
                    array('customer.is_guest', 'customer.date_add', 'orders.id_customer'),
                    'LEFT JOIN `' . _DB_PREFIX_ . 'orders` `o` ON `o`.`id_customer` = `t`.`id_customer`'
                ),
                self::orphan('customer_group', 'id_customer', 'customer', 'id_customer', 'Grupos de clientes que ya no existen', 0),
                self::item(
                    'address',
                    'Direcciones de clientes que ya no existen y sin ningún pedido',
                    1,
                    '`t`.`id_customer` != 0 AND `c`.`id_customer` IS NULL AND `oe`.`id_order` IS NULL AND `oi`.`id_order` IS NULL',
                    array('address.id_customer', 'customer.id_customer', 'orders.id_address_delivery', 'orders.id_address_invoice'),
                    'LEFT JOIN `' . _DB_PREFIX_ . 'customer` `c` ON `c`.`id_customer` = `t`.`id_customer`'
                    . ' LEFT JOIN `' . _DB_PREFIX_ . 'orders` `oe` ON `oe`.`id_address_delivery` = `t`.`id_address`'
                    . ' LEFT JOIN `' . _DB_PREFIX_ . 'orders` `oi` ON `oi`.`id_address_invoice` = `t`.`id_address`'
                ),
                self::item(
                    'guest',
                    'Invitados anónimos sin visitas, sin carrito y sin cliente',
                    1,
                    '(`t`.`id_customer` IS NULL OR `cu`.`id_customer` IS NULL) AND `c`.`id_guest` IS NULL AND `ca`.`id_guest` IS NULL',
                    array('guest.id_customer', 'connections.id_guest', 'cart.id_guest', 'customer.id_customer'),
                    'LEFT JOIN `' . _DB_PREFIX_ . 'connections` `c` ON `c`.`id_guest` = `t`.`id_guest`'
                    . ' LEFT JOIN `' . _DB_PREFIX_ . 'cart` `ca` ON `ca`.`id_guest` = `t`.`id_guest`'
                    . ' LEFT JOIN `' . _DB_PREFIX_ . 'customer` `cu` ON `cu`.`id_customer` = `t`.`id_customer`'
                ),
            ),
        );

        /* --- Carritos abandonados -------------------------------------- */
        $groups['carritos'] = array(
            'name' => 'Carritos abandonados',
            'help' => 'Carritos de más de ' . $mesesCarritos . ' ' . ($mesesCarritos === 1 ? 'mes' : 'meses') . ' que nunca llegaron a ser pedido. '
                . 'Un carrito con pedido no se borra nunca. Ojo: las estadísticas de carritos abandonados contarán menos.',
            'items' => array(
                self::item(
                    'cart',
                    'Carritos sin pedido',
                    1,
                    '`t`.`date_add` < \'' . pSQL($oldCarts) . '\' AND `o`.`id_order` IS NULL',
                    array('cart.date_add', 'orders.id_cart'),
                    'LEFT JOIN `' . _DB_PREFIX_ . 'orders` `o` ON `o`.`id_cart` = `t`.`id_cart`'
                ),
                self::orphan('cart_product', 'id_cart', 'cart', 'id_cart', 'Líneas de carritos que ya no existen', 0),
                self::orphan('cart_cart_rule', 'id_cart', 'cart', 'id_cart', 'Cupones de carritos que ya no existen', 0),
                self::orphan('customization', 'id_cart', 'cart', 'id_cart', 'Personalizaciones de carritos que ya no existen', 1),
                self::orphan('specific_price', 'id_cart', 'cart', 'id_cart', 'Precios puntuales de carritos que ya no existen', 0, '`t`.`id_cart` != 0'),
            ),
        );

        /* --- Filas huérfanas ------------------------------------------- */
        $groups['huerfanos'] = array(
            'name' => 'Filas huérfanas',
            'help' => 'Filas que apuntan a productos, imágenes o fichas que ya no existen: solo ocupan sitio y ralentizan consultas. '
                . 'El orden importa, así que se limpian de arriba abajo.',
            'items' => array(
                self::orphan('image', 'id_product', 'product', 'id_product', 'Imágenes de productos borrados', 1),
                self::orphan('image_lang', 'id_image', 'image', 'id_image', 'Textos de imágenes que ya no existen', 0),
                self::orphan('image_shop', 'id_image', 'image', 'id_image', 'Imágenes por tienda que ya no existen', 0),
                self::orphan('specific_price', 'id_product', 'product', 'id_product', 'Precios específicos de productos borrados', 1, '`t`.`id_product` != 0'),
                self::orphan('stock_available', 'id_product', 'product', 'id_product', 'Stock de productos borrados', 1, '`t`.`id_product` != 0'),
                self::orphan('category_product', 'id_product', 'product', 'id_product', 'Productos en categorías que ya no existen', 1),
                self::orphan('product_attribute', 'id_product', 'product', 'id_product', 'Combinaciones de productos borrados', 1),
                self::orphan('product_attribute_combination', 'id_product_attribute', 'product_attribute', 'id_product_attribute', 'Atributos de combinaciones que ya no existen', 0),
                self::orphan('product_attribute_shop', 'id_product_attribute', 'product_attribute', 'id_product_attribute', 'Combinaciones por tienda que ya no existen', 0),
                self::orphan('feature_product', 'id_product', 'product', 'id_product', 'Características de productos borrados', 0),
                self::orphan('product_tag', 'id_product', 'product', 'id_product', 'Etiquetas de productos borrados', 0),
                self::orphan('product_supplier', 'id_product', 'product', 'id_product', 'Proveedores de productos borrados', 0),
                self::orphan('search_index', 'id_product', 'product', 'id_product', 'Índice de búsqueda de productos borrados', 0),
                self::orphan('search_word', 'id_word', 'search_index', 'id_word', 'Palabras del buscador que ya no se usan', 0),
                self::orphan('customer_message', 'id_customer_thread', 'customer_thread', 'id_customer_thread', 'Mensajes de conversaciones que ya no existen', 0),
                self::orphan('page_viewed', 'id_page', 'page', 'id_page', 'Visitas a páginas que ya no existen', 0),
                self::item(
                    'page',
                    'Páginas de estadísticas sin ninguna visita',
                    0,
                    '`v`.`id_page` IS NULL AND `c`.`id_page` IS NULL',
                    array('page.id_page', 'page_viewed.id_page', 'connections.id_page'),
                    'LEFT JOIN `' . _DB_PREFIX_ . 'page_viewed` `v` ON `v`.`id_page` = `t`.`id_page`'
                    . ' LEFT JOIN `' . _DB_PREFIX_ . 'connections` `c` ON `c`.`id_page` = `t`.`id_page`'
                ),
            ),
        );

        /* --- Índice del buscador --------------------------------------- */
        $groups['busqueda'] = array(
            'name' => 'Índice del buscador',
            'help' => 'Esto no es basura: es el índice que usa el buscador de la tienda, y en catálogos grandes pesa mucho. '
                . 'Se vacía entero y hay que reconstruirlo después en Preferencias > Buscar > Reconstruir el índice completo. '
                . 'Mientras no se reconstruya, el buscador de la tienda no encuentra nada.',
            'items' => array(
                self::truncateItem('search_index', 'Índice de búsqueda', 2),
                self::truncateItem('search_word', 'Palabras del buscador', 2),
            ),
        );

        /* Quitar los apartados que no existen en esta tienda y marcar motivos */
        foreach ($groups as $id => $group) {
            $items = array();
            foreach ($group['items'] as $item) {
                $item = self::resolve($item);
                if ($item !== null) {
                    $items[$item['id']] = $item;
                }
            }
            if (!$items) {
                unset($groups[$id]);
                continue;
            }
            $groups[$id]['items'] = $items;
        }

        return $groups;
    }

    /**
     * Apartado normal: borra de una tabla las filas que cumplen la condición.
     *
     * @param string $table    tabla sin prefijo
     * @param string $name     nombre para la interfaz
     * @param int    $risk     0 seguro, 1 revisar, 2 confirmar
     * @param string $where    condición con el alias `t`
     * @param array  $requires tablas y columnas que deben existir ('tabla' o 'tabla.columna')
     * @param string $joins    uniones opcionales
     */
    protected static function item($table, $name, $risk, $where, array $requires = array(), $joins = '')
    {
        return array(
            'id' => $table,
            'table' => $table,
            'name' => $name,
            'risk' => (int) $risk,
            'where' => $where,
            'joins' => $joins,
            'requires' => array_merge(array($table), $requires),
            'truncate' => false,
            'backup' => true,
        );
    }

    /**
     * Apartado de filas huérfanas: las de $table cuya $column no está en $parent.
     */
    protected static function orphan($table, $column, $parent, $parentColumn, $name, $risk, $extra = '')
    {
        $where = '`p`.`' . bqSQL($parentColumn) . '` IS NULL' . ($extra !== '' ? ' AND ' . $extra : '');

        return array(
            'id' => $table . '__' . $column,
            'table' => $table,
            'name' => $name,
            'risk' => (int) $risk,
            'where' => $where,
            'joins' => 'LEFT JOIN `' . _DB_PREFIX_ . bqSQL($parent) . '` `p` ON `p`.`' . bqSQL($parentColumn) . '` = `t`.`' . bqSQL($column) . '`',
            'requires' => array($table . '.' . $column, $parent . '.' . $parentColumn),
            'truncate' => false,
            'backup' => true,
        );
    }

    /**
     * Apartado que vacía la tabla entera (caché o índices que se regeneran).
     */
    protected static function truncateItem($table, $name, $risk)
    {
        return array(
            'id' => 'truncate_' . $table,
            'table' => $table,
            'name' => $name,
            'risk' => (int) $risk,
            'where' => '',
            'joins' => '',
            'requires' => array($table),
            'truncate' => true,
            'backup' => false,
        );
    }

    /**
     * Comprueba contra la base de datos que el apartado se puede usar en esta
     * tienda. Devuelve null si falta la tabla, y el motivo dentro del apartado
     * si falta una columna o la clave primaria.
     */
    protected static function resolve($item)
    {
        foreach ($item['requires'] as $required) {
            if (strpos($required, '.') === false) {
                if (!JosralimpiezaCore::tableExists($required)) {
                    return null;
                }
                continue;
            }
            list($table, $column) = explode('.', $required, 2);
            if (!JosralimpiezaCore::tableExists($table)) {
                return null;
            }
            if (!JosralimpiezaCore::columnExists($table, $column)) {
                $item['unavailable'] = 'Esta versión de PrestaShop no tiene la columna ' . $column . ' en ' . _DB_PREFIX_ . $table . '.';

                return $item;
            }
        }
        if (!$item['truncate'] && !self::primaryKey($item['table'])) {
            $item['unavailable'] = 'La tabla ' . _DB_PREFIX_ . $item['table'] . ' no tiene clave primaria, así que no se puede borrar fila a fila con seguridad.';
        }

        return $item;
    }

    /**
     * Busca un apartado por su id.
     */
    public static function findItem($id)
    {
        foreach (self::groups() as $group) {
            if (isset($group['items'][$id])) {
                return $group['items'][$id];
            }
        }

        return null;
    }

    protected static function from($item)
    {
        return '`' . _DB_PREFIX_ . bqSQL($item['table']) . '` `t`'
            . ($item['joins'] !== '' ? ' ' . $item['joins'] : '')
            . ($item['where'] !== '' ? ' WHERE ' . $item['where'] : '');
    }

    /**
     * Cuántas filas se borrarían.
     *
     * @return int|string número o el motivo por el que no se puede contar
     */
    public static function countItem($item)
    {
        if (isset($item['unavailable'])) {
            return $item['unavailable'];
        }
        $value = Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . self::from($item));
        if ($value === false) {
            return 'No se pudo contar en ' . _DB_PREFIX_ . $item['table'] . ': ' . Db::getInstance()->getMsgError();
        }

        return (int) $value;
    }

    /**
     * Muestra unas cuantas filas de las que se van a borrar, para verlas antes.
     *
     * @return array|string
     */
    public static function previewItem($item, $limit = 15)
    {
        if (isset($item['unavailable'])) {
            return $item['unavailable'];
        }
        $rows = Db::getInstance()->executeS(
            'SELECT `t`.* FROM ' . self::from($item) . ' LIMIT ' . (int) $limit
        );
        if (!is_array($rows)) {
            return 'No se pudo leer de ' . _DB_PREFIX_ . $item['table'] . ': ' . Db::getInstance()->getMsgError();
        }
        foreach ($rows as &$row) {
            foreach ($row as $column => $value) {
                if ($value !== null && Tools::strlen((string) $value) > 160) {
                    $row[$column] = Tools::substr((string) $value, 0, 157) . '...';
                }
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Borra un lote de filas del apartado, guardando antes una copia.
     *
     * @param array $item
     * @param int   $limit     filas de este lote
     * @param int   $idBackup  copia abierta (0 en los apartados sin copia)
     *
     * @return array|string array('deleted' => n, 'done' => bool) o el motivo del fallo
     */
    public static function cleanItem($item, $limit, $idBackup)
    {
        if (isset($item['unavailable'])) {
            return $item['unavailable'];
        }
        $table = '`' . _DB_PREFIX_ . bqSQL($item['table']) . '`';

        if ($item['truncate']) {
            if (!Db::getInstance()->execute('TRUNCATE TABLE ' . $table)) {
                return 'No se pudo vaciar ' . _DB_PREFIX_ . $item['table'] . ': ' . Db::getInstance()->getMsgError();
            }

            return array('deleted' => 0, 'done' => true, 'truncated' => true);
        }

        $keys = self::primaryKey($item['table']);
        $rows = Db::getInstance()->executeS(
            'SELECT `t`.* FROM ' . self::from($item) . ' LIMIT ' . (int) $limit
        );
        if (!is_array($rows)) {
            return 'No se pudo leer de ' . _DB_PREFIX_ . $item['table'] . ': ' . Db::getInstance()->getMsgError();
        }
        if (!$rows) {
            return array('deleted' => 0, 'done' => true);
        }

        // La copia previa va primero: si falla, no se borra nada
        if ($item['backup'] && $idBackup) {
            $saved = JosralimpiezaBackup::append($idBackup, $item['table'], $rows);
            if ($saved !== true) {
                return $saved;
            }
        }

        // Se borra exactamente lo que se acaba de guardar, por su clave primaria
        if (count($keys) === 1) {
            $key = $keys[0];
            $ids = array();
            foreach ($rows as $row) {
                $ids[] = (int) $row[$key];
            }
            $condition = '`' . bqSQL($key) . '` IN (' . implode(', ', $ids) . ')';
        } else {
            $tuples = array();
            foreach ($rows as $row) {
                $parts = array();
                foreach ($keys as $key) {
                    $value = isset($row[$key]) ? $row[$key] : null;
                    $parts[] = '`' . bqSQL($key) . '` ' . ($value === null ? 'IS NULL' : '= \'' . pSQL($value) . '\'');
                }
                $tuples[] = '(' . implode(' AND ', $parts) . ')';
            }
            $condition = implode(' OR ', $tuples);
        }

        if (!Db::getInstance()->execute('DELETE FROM ' . $table . ' WHERE ' . $condition)) {
            return 'No se pudo borrar de ' . _DB_PREFIX_ . $item['table'] . ': ' . Db::getInstance()->getMsgError();
        }
        $deleted = (int) Db::getInstance()->Affected_Rows();

        return array('deleted' => $deleted, 'done' => count($rows) < (int) $limit);
    }

    /**
     * Apartados de riesgo cero, los únicos que puede tocar el automático.
     */
    public static function safeItems()
    {
        $safe = array();
        foreach (self::groups() as $idGroup => $group) {
            foreach ($group['items'] as $id => $item) {
                if ((int) $item['risk'] === 0 && !isset($item['unavailable'])) {
                    $safe[$id] = $group['name'] . ' › ' . $item['name'];
                }
            }
        }

        return $safe;
    }
}
