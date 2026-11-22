<?php
/**
 * Utilidades comunes del módulo: ajustes, comprobación del esquema real de la
 * tienda, carpetas de almacenamiento, historial y tamaños de las tablas.
 *
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosralimpiezaCore
{
    /** @var array|null nombres reales de las tablas de esta base de datos */
    protected static $tables = null;

    /** @var array columnas reales por tabla */
    protected static $columns = array();

    /** @var array|null estado de las tablas, leído una sola vez por petición */
    protected static $status = null;

    /* ------------------------------------------------------------------ */
    /* Ajustes                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Valores por defecto de todos los ajustes. Se mezclan con lo guardado, así
     * que un ajuste que nunca se guardó (o una casilla desmarcada que no llega
     * en el POST) no deja la clave sin valor.
     */
    public static function defaults()
    {
        return array(
            'ANTIGUEDAD_REGISTROS' => 3,
            'ANTIGUEDAD_CARRITOS' => 6,
            'RETENCION' => 30,
            'LOTE_BD' => 2000,
            'LOTE_IMG' => 25,
            'MANTENER_WATERMARK' => 1,
            'CRON_FRECUENCIA' => 'off',
            'CRON_HORA' => 4,
            'CRON_REGISTROS' => 1,
            'CRON_CACHE' => 1,
            'CRON_HUERFANOS' => 0,
            'CORREO_ACTIVO' => 1,
            'CORREO' => '',
        );
    }

    public static function key($name)
    {
        return 'JOSRALIMPIEZA_' . Tools::strtoupper($name);
    }

    /**
     * Todos los ajustes, siempre completos.
     */
    public static function settings()
    {
        $settings = self::defaults();
        foreach ($settings as $name => $default) {
            $value = Configuration::getGlobalValue(self::key($name));
            if ($value !== false && $value !== null && $value !== '') {
                $settings[$name] = $value;
            }
        }
        $settings['ANTIGUEDAD_REGISTROS'] = max(0, (int) $settings['ANTIGUEDAD_REGISTROS']);
        $settings['ANTIGUEDAD_CARRITOS'] = max(0, (int) $settings['ANTIGUEDAD_CARRITOS']);
        $settings['RETENCION'] = max(1, (int) $settings['RETENCION']);
        $settings['LOTE_BD'] = min(50000, max(50, (int) $settings['LOTE_BD']));
        $settings['LOTE_IMG'] = min(500, max(5, (int) $settings['LOTE_IMG']));
        $settings['MANTENER_WATERMARK'] = (int) $settings['MANTENER_WATERMARK'] ? 1 : 0;
        $settings['CORREO_ACTIVO'] = (int) $settings['CORREO_ACTIVO'] ? 1 : 0;
        $settings['CRON_REGISTROS'] = (int) $settings['CRON_REGISTROS'] ? 1 : 0;
        $settings['CRON_CACHE'] = (int) $settings['CRON_CACHE'] ? 1 : 0;
        $settings['CRON_HUERFANOS'] = (int) $settings['CRON_HUERFANOS'] ? 1 : 0;
        $settings['CRON_HORA'] = min(23, max(0, (int) $settings['CRON_HORA']));
        if (!in_array($settings['CRON_FRECUENCIA'], array('off', 'diaria', 'semanal', 'mensual'), true)) {
            $settings['CRON_FRECUENCIA'] = 'off';
        }
        if (trim((string) $settings['CORREO']) === '') {
            $settings['CORREO'] = (string) Configuration::get('PS_SHOP_EMAIL');
        }

        return $settings;
    }

    public static function setting($name)
    {
        $settings = self::settings();

        return isset($settings[$name]) ? $settings[$name] : null;
    }

    public static function saveSettings(array $values)
    {
        foreach (self::defaults() as $name => $default) {
            if (array_key_exists($name, $values)) {
                Configuration::updateGlobalValue(self::key($name), $values[$name]);
            }
        }
    }

    /**
     * Apartados que puede tocar el automático: solo los de riesgo cero de los
     * grupos que estén activados en los ajustes.
     *
     * @return array id => apartado
     */
    public static function cronItems()
    {
        $settings = self::settings();
        $enabled = array(
            'registros' => (int) $settings['CRON_REGISTROS'],
            'cache' => (int) $settings['CRON_CACHE'],
            'huerfanos' => (int) $settings['CRON_HUERFANOS'],
        );
        $items = array();
        foreach (JosralimpiezaDb::groups() as $idGroup => $group) {
            if (empty($enabled[$idGroup])) {
                continue;
            }
            foreach ($group['items'] as $id => $item) {
                if ((int) $item['risk'] === 0 && !isset($item['unavailable'])) {
                    $item['group'] = $group['name'];
                    $items[$id] = $item;
                }
            }
        }

        return $items;
    }

    /* ------------------------------------------------------------------ */
    /* Esquema real de la tienda                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Comprueba contra la base de datos si la tabla existe de verdad: las tablas
     * de estadísticas y de módulos no están en todas las tiendas.
     *
     * @param string $table nombre sin prefijo
     */
    public static function tableExists($table)
    {
        if (self::$tables === null) {
            self::$tables = array();
            $rows = Db::getInstance()->executeS('SHOW TABLES');
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $name = reset($row);
                    if (is_string($name) && strpos($name, _DB_PREFIX_) === 0) {
                        self::$tables[Tools::substr($name, Tools::strlen(_DB_PREFIX_))] = true;
                    }
                }
            }
        }

        return isset(self::$tables[$table]);
    }

    /**
     * @param string $table nombre sin prefijo
     * @param string $column
     */
    public static function columnExists($table, $column)
    {
        if (!self::tableExists($table)) {
            return false;
        }
        if (!isset(self::$columns[$table])) {
            self::$columns[$table] = array();
            $rows = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . bqSQL($table) . '`');
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (isset($row['Field'])) {
                        self::$columns[$table][$row['Field']] = true;
                    }
                }
            }
        }

        return isset(self::$columns[$table][$column]);
    }

    /* ------------------------------------------------------------------ */
    /* Carpetas de copias y cuarentena                                     */
    /* ------------------------------------------------------------------ */

    public static function storageDir()
    {
        return _PS_MODULE_DIR_ . 'josralimpieza/storage/';
    }

    public static function backupDir()
    {
        return self::storageDir() . 'copias/';
    }

    public static function quarantineDir()
    {
        return self::storageDir() . 'cuarentena/';
    }

    /**
     * Crea las carpetas con su protección. Se llama al instalar y cada vez que
     * se abre la configuración, por si el módulo se subió por FTP.
     *
     * @return true|string true o el motivo del fallo
     */
    public static function ensureStorage()
    {
        $htaccess = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
        foreach (array(self::storageDir(), self::backupDir(), self::quarantineDir()) as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return 'No se puede crear la carpeta ' . $dir . ': revisa los permisos de la carpeta del módulo.';
            }
            if (!is_writable($dir)) {
                return 'No se puede escribir en ' . $dir . ': revisa los permisos (el usuario de PHP necesita escritura).';
            }
            if (!file_exists($dir . 'index.php')) {
                @file_put_contents($dir . 'index.php', "<?php\nheader('Location: ../');\nexit;\n");
            }
            if (!file_exists($dir . '.htaccess')) {
                @file_put_contents($dir . '.htaccess', $htaccess);
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Historial                                                           */
    /* ------------------------------------------------------------------ */

    public static function addHistory($action, $detail, $items = 0, $bytes = 0, $idBackup = 0, $source = 'manual')
    {
        if (!self::tableExists('josralimpieza_history')) {
            return;
        }
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'josralimpieza_history`
                (`action`, `detail`, `items`, `bytes`, `source`, `id_backup`, `date_add`)
             VALUES (\'' . pSQL($action) . '\', \'' . pSQL($detail) . '\', ' . (int) $items . ', '
                . (int) $bytes . ', \'' . pSQL($source) . '\', ' . (int) $idBackup . ', \'' . pSQL(date('Y-m-d H:i:s')) . '\')'
        );
    }

    public static function history($limit = 60)
    {
        if (!self::tableExists('josralimpieza_history')) {
            return array();
        }
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josralimpieza_history` ORDER BY `id_history` DESC LIMIT ' . (int) $limit
        );

        return is_array($rows) ? $rows : array();
    }

    /* ------------------------------------------------------------------ */
    /* Tamaños y optimización                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Estado real de las tablas de la tienda: tamaño, espacio desperdiciado y motor.
     */
    public static function tableStatus($refresh = false)
    {
        if (self::$status !== null && !$refresh) {
            return self::$status;
        }
        $rows = Db::getInstance()->executeS('SHOW TABLE STATUS');
        $tables = array();
        if (!is_array($rows)) {
            return $tables;
        }
        foreach ($rows as $row) {
            if (!isset($row['Name']) || strpos($row['Name'], _DB_PREFIX_) !== 0) {
                continue;
            }
            $data = isset($row['Data_length']) ? (float) $row['Data_length'] : 0;
            $index = isset($row['Index_length']) ? (float) $row['Index_length'] : 0;
            $free = isset($row['Data_free']) ? (float) $row['Data_free'] : 0;
            $tables[] = array(
                'name' => $row['Name'],
                'engine' => isset($row['Engine']) ? (string) $row['Engine'] : '',
                'rows' => isset($row['Rows']) ? (int) $row['Rows'] : 0,
                'size' => $data + $index,
                'free' => $free,
            );
        }
        usort($tables, array('JosralimpiezaCore', 'sortBySize'));
        self::$status = $tables;

        return $tables;
    }

    /**
     * Filas que dice tener la tabla. En InnoDB es una estimación del propio
     * MySQL, no un recuento: vale para hacerse una idea, no para decidir.
     *
     * @param string $table nombre sin prefijo
     */
    public static function approxRows($table)
    {
        foreach (self::tableStatus() as $status) {
            if ($status['name'] === _DB_PREFIX_ . $table) {
                return (int) $status['rows'];
            }
        }

        return 0;
    }

    public static function sortBySize($a, $b)
    {
        if ($a['size'] == $b['size']) {
            return 0;
        }

        return $a['size'] < $b['size'] ? 1 : -1;
    }

    /**
     * Optimiza una tabla de la tienda. Devuelve el espacio recuperado en bytes.
     *
     * @param string $name nombre completo, con prefijo
     *
     * @return array|string datos o el motivo del fallo
     */
    public static function optimizeTable($name)
    {
        if (strpos($name, _DB_PREFIX_) !== 0 || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            return 'La tabla ' . $name . ' no es de esta tienda, no se toca.';
        }
        $before = 0;
        foreach (self::tableStatus() as $table) {
            if ($table['name'] === $name) {
                $before = $table['size'] + $table['free'];
                break;
            }
        }
        if (!Db::getInstance()->execute('OPTIMIZE TABLE `' . bqSQL($name) . '`')) {
            return 'No se pudo optimizar ' . $name . ': ' . Db::getInstance()->getMsgError();
        }
        self::$tables = null;
        self::$status = null;
        $after = 0;
        $rows = Db::getInstance()->executeS('SHOW TABLE STATUS');
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (isset($row['Name']) && $row['Name'] === $name) {
                    $after = (float) (isset($row['Data_length']) ? $row['Data_length'] : 0)
                        + (float) (isset($row['Index_length']) ? $row['Index_length'] : 0)
                        + (float) (isset($row['Data_free']) ? $row['Data_free'] : 0);
                    break;
                }
            }
        }

        return array('name' => $name, 'saved' => max(0, $before - $after));
    }

    /* ------------------------------------------------------------------ */
    /* Varios                                                             */
    /* ------------------------------------------------------------------ */

    public static function humanBytes($bytes)
    {
        $bytes = (float) $bytes;
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i = 0;
        while ($bytes >= 1024 && $i < 4) {
            $bytes /= 1024;
            ++$i;
        }

        return number_format($bytes, $i > 1 ? 2 : 0, ',', '.') . ' ' . $units[$i];
    }

    /** Fecha límite: lo anterior a esta fecha se considera antiguo */
    public static function cutoff($months)
    {
        $months = (int) $months;
        if ($months <= 0) {
            return date('Y-m-d H:i:s');
        }

        return date('Y-m-d H:i:s', strtotime('-' . $months . ' months'));
    }

    public static function now()
    {
        return date('d/m/Y H:i');
    }

    /** Peso total de una carpeta y número de archivos */
    public static function dirSize($dir)
    {
        $total = array('files' => 0, 'bytes' => 0);
        if (!is_dir($dir)) {
            return $total;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                ++$total['files'];
                $total['bytes'] += (float) $item->getSize();
            }
        }

        return $total;
    }
}
