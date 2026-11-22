<?php
/**
 * Copias previas a cualquier cambio.
 *
 * - Base de datos: las filas que se van a borrar se guardan como INSERT en un
 *   archivo .sql (comprimido a .sql.gz al terminar) dentro de storage/copias/.
 * - Imágenes: los archivos no se borran, se mueven a storage/cuarentena/<lote>/
 *   conservando su ruta dentro de img/, para poder devolverlos a su sitio.
 *
 * Las copias se borran solas cuando pasan los días de retención configurados.
 *
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosralimpiezaBackup
{
    /**
     * Abre una copia nueva y devuelve su id.
     *
     * @param string $kind bd|imagenes
     * @param string $label qué se está limpiando
     *
     * @return int|string id de la copia o el motivo del fallo
     */
    public static function start($kind, $label)
    {
        $storage = JosralimpiezaCore::ensureStorage();
        if ($storage !== true) {
            return $storage;
        }
        $stamp = date('Ymd-His');
        if ($kind === 'imagenes') {
            $name = 'lote-' . $stamp;
            $dir = JosralimpiezaCore::quarantineDir() . $name . '/';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return 'No se puede crear la carpeta de cuarentena ' . $dir . ' (permisos).';
            }
        } else {
            $name = 'bd-' . $stamp . '-' . Tools::substr(md5(uniqid('', true)), 0, 6) . '.sql';
            $header = '-- Copia previa de josralimpieza ' . self::version() . "\n"
                . '-- ' . $label . "\n"
                . '-- Fecha: ' . date('d/m/Y H:i:s') . "\n"
                . '-- Tienda: ' . Tools::getShopDomainSsl(false) . __PS_BASE_URI__ . "\n"
                . '-- Se restaura desde la pestaña Copias del propio módulo.' . "\n";
            if (@file_put_contents(JosralimpiezaCore::backupDir() . $name, $header) === false) {
                return 'No se puede escribir la copia en ' . JosralimpiezaCore::backupDir() . ' (permisos).';
            }
        }

        if (!Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'josralimpieza_backup`
                (`kind`, `label`, `filename`, `items`, `bytes`, `restored`, `date_add`)
             VALUES (\'' . pSQL($kind) . '\', \'' . pSQL($label) . '\', \'' . pSQL($name) . '\', 0, 0, 0, \'' . pSQL(date('Y-m-d H:i:s')) . '\')'
        )) {
            return 'No se pudo registrar la copia previa: ' . Db::getInstance()->getMsgError();
        }

        return (int) Db::getInstance()->Insert_ID();
    }

    protected static function version()
    {
        $module = Module::getInstanceByName('josralimpieza');

        return $module ? $module->version : '';
    }

    public static function get($id)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josralimpieza_backup` WHERE `id_backup` = ' . (int) $id
        );

        return is_array($row) && $row ? $row : null;
    }

    public static function path($backup)
    {
        if ($backup['kind'] === 'imagenes') {
            return JosralimpiezaCore::quarantineDir() . $backup['filename'] . '/';
        }

        return JosralimpiezaCore::backupDir() . $backup['filename'];
    }

    /**
     * Añade filas a una copia de base de datos.
     *
     * @param int    $id    copia
     * @param string $table nombre sin prefijo
     * @param array  $rows  filas completas tal como están en la tabla
     *
     * @return true|string
     */
    public static function append($id, $table, array $rows)
    {
        if (!$rows) {
            return true;
        }
        $backup = self::get($id);
        if (!$backup || $backup['kind'] !== 'bd') {
            return 'La copia previa número ' . (int) $id . ' no existe: no se borra nada.';
        }
        $file = self::path($backup);
        if (!file_exists($file) || !is_writable($file)) {
            return 'No se puede escribir en la copia previa ' . $file . ': no se borra nada.';
        }

        $sql = '';
        $columns = array_keys($rows[0]);
        $names = '';
        foreach ($columns as $column) {
            $names .= ($names === '' ? '' : ', ') . '`' . bqSQL($column) . '`';
        }
        foreach ($rows as $row) {
            $values = '';
            foreach ($columns as $column) {
                $value = isset($row[$column]) ? $row[$column] : null;
                $values .= ($values === '' ? '' : ', ')
                    . ($value === null ? 'NULL' : '\'' . pSQL($value, true) . '\'');
            }
            $sql .= 'INSERT IGNORE INTO `' . _DB_PREFIX_ . bqSQL($table) . '` (' . $names . ') VALUES (' . $values . ');' . "\n";
        }

        if (@file_put_contents($file, $sql, FILE_APPEND) === false) {
            return 'No se pudo añadir a la copia previa ' . $file . ' (espacio en disco o permisos): no se borra nada.';
        }
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'josralimpieza_backup`
                SET `items` = `items` + ' . (int) count($rows) . ', `bytes` = ' . (int) @filesize($file) . '
              WHERE `id_backup` = ' . (int) $id
        );

        return true;
    }

    /**
     * Mueve un archivo a la cuarentena conservando su ruta dentro de img/.
     *
     * @param int    $id   copia de tipo imagenes
     * @param string $file ruta absoluta del archivo
     *
     * @return true|string
     */
    public static function quarantine($id, $file)
    {
        $backup = self::get($id);
        if (!$backup || $backup['kind'] !== 'imagenes') {
            return 'El lote de cuarentena número ' . (int) $id . ' no existe: no se mueve nada.';
        }
        if (!file_exists($file)) {
            return 'El archivo ' . $file . ' ya no está en la tienda.';
        }
        if (strpos($file, _PS_IMG_DIR_) !== 0) {
            return 'El archivo ' . $file . ' está fuera de la carpeta de imágenes: no se toca.';
        }
        $relative = Tools::substr($file, Tools::strlen(_PS_IMG_DIR_));
        $target = self::path($backup) . $relative;
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
            return 'No se puede crear ' . dirname($target) . ' en la cuarentena (permisos).';
        }
        $bytes = (int) @filesize($file);
        if (!@rename($file, $target)) {
            if (!@copy($file, $target)) {
                return 'No se pudo mover ' . $relative . ' a la cuarentena (permisos de img/ o espacio en disco).';
            }
            @unlink($file);
        }
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'josralimpieza_backup`
                SET `items` = `items` + 1, `bytes` = `bytes` + ' . $bytes . '
              WHERE `id_backup` = ' . (int) $id
        );

        return true;
    }

    /**
     * Cierra la copia: comprime el .sql si el servidor tiene zlib.
     */
    public static function finish($id)
    {
        $backup = self::get($id);
        if (!$backup) {
            return;
        }
        if ((int) $backup['items'] === 0) {
            self::remove($id);

            return;
        }
        if ($backup['kind'] !== 'bd' || !function_exists('gzopen') || Tools::substr($backup['filename'], -4) !== '.sql') {
            return;
        }
        $file = self::path($backup);
        $gz = @gzopen($file . '.gz', 'wb9');
        $in = @fopen($file, 'rb');
        if (!$gz || !$in) {
            if ($gz) {
                gzclose($gz);
            }
            if ($in) {
                fclose($in);
            }
            @unlink($file . '.gz');

            return;
        }
        while (!feof($in)) {
            gzwrite($gz, fread($in, 262144));
        }
        fclose($in);
        gzclose($gz);
        @unlink($file);
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'josralimpieza_backup`
                SET `filename` = \'' . pSQL($backup['filename'] . '.gz') . '\', `bytes` = ' . (int) @filesize($file . '.gz') . '
              WHERE `id_backup` = ' . (int) $id
        );
    }

    public static function listAll($limit = 100)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josralimpieza_backup` ORDER BY `id_backup` DESC LIMIT ' . (int) $limit
        );
        if (!is_array($rows)) {
            return array();
        }
        foreach ($rows as &$row) {
            $row['exists'] = file_exists(self::path($row));
            $row['human'] = JosralimpiezaCore::humanBytes($row['bytes']);
            $row['fecha'] = date('d/m/Y H:i', strtotime($row['date_add']));
        }
        unset($row);

        return $rows;
    }

    /**
     * Devuelve las filas o los archivos de una copia a su sitio.
     *
     * @return array|string resumen o el motivo del fallo
     */
    public static function restore($id)
    {
        $backup = self::get($id);
        if (!$backup) {
            return 'Esa copia ya no existe.';
        }
        $path = self::path($backup);
        if (!file_exists($path)) {
            return 'El archivo de la copia (' . $path . ') ya no está: no se puede restaurar.';
        }

        if ($backup['kind'] === 'imagenes') {
            return self::restoreImages($backup, $path);
        }

        return self::restoreDb($backup, $path);
    }

    protected static function restoreDb($backup, $path)
    {
        $gz = Tools::substr($path, -3) === '.gz';
        $handle = $gz ? @gzopen($path, 'rb') : @fopen($path, 'rb');
        if (!$handle) {
            return 'No se pudo abrir la copia ' . $path . '.';
        }
        $done = 0;
        $failed = 0;
        $error = '';
        $buffer = '';
        while (!($gz ? gzeof($handle) : feof($handle))) {
            $line = $gz ? gzgets($handle, 1048576) : fgets($handle, 1048576);
            if ($line === false) {
                break;
            }
            $buffer .= $line;
            if (Tools::substr(rtrim($buffer), -1) !== ';') {
                continue;
            }
            $query = trim($buffer);
            $buffer = '';
            if ($query === '' || Tools::substr($query, 0, 2) === '--') {
                continue;
            }
            if (Db::getInstance()->execute(rtrim($query, ';'))) {
                ++$done;
            } else {
                ++$failed;
                if ($error === '') {
                    $error = Db::getInstance()->getMsgError();
                }
            }
        }
        $gz ? gzclose($handle) : fclose($handle);

        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'josralimpieza_backup` SET `restored` = 1 WHERE `id_backup` = ' . (int) $backup['id_backup']
        );
        JosralimpiezaCore::addHistory(
            'Restaurar copia de base de datos',
            $backup['label'] . ' — ' . $done . ' filas devueltas' . ($failed ? ', ' . $failed . ' fallaron (' . $error . ')' : ''),
            $done,
            0,
            (int) $backup['id_backup']
        );

        if ($failed) {
            return 'Se devolvieron ' . $done . ' filas, pero ' . $failed . ' fallaron. Primer error: ' . $error;
        }

        return array('items' => $done, 'message' => 'Devueltas ' . $done . ' filas a la base de datos.');
    }

    protected static function restoreImages($backup, $path)
    {
        $moved = 0;
        $failed = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', Tools::substr($item->getPathname(), Tools::strlen($path)));
            $target = _PS_IMG_DIR_ . $relative;
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
                $failed[] = $relative;
                continue;
            }
            if (@rename($item->getPathname(), $target)) {
                ++$moved;
            } elseif (@copy($item->getPathname(), $target)) {
                @unlink($item->getPathname());
                ++$moved;
            } else {
                $failed[] = $relative;
            }
        }

        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'josralimpieza_backup` SET `restored` = 1 WHERE `id_backup` = ' . (int) $backup['id_backup']
        );
        JosralimpiezaCore::addHistory(
            'Restaurar imágenes de la cuarentena',
            $backup['label'] . ' — ' . $moved . ' archivos devueltos a img/'
                . ($failed ? ', ' . count($failed) . ' no se pudieron mover' : ''),
            $moved,
            0,
            (int) $backup['id_backup']
        );

        if ($failed) {
            return 'Se devolvieron ' . $moved . ' archivos, pero ' . count($failed) . ' no se pudieron mover (permisos en img/). El primero: ' . $failed[0];
        }

        return array('items' => $moved, 'message' => 'Devueltos ' . $moved . ' archivos a su sitio en img/.');
    }

    /**
     * Borra una copia para siempre (el archivo o la carpeta y su registro).
     */
    public static function remove($id)
    {
        $backup = self::get($id);
        if (!$backup) {
            return false;
        }
        $path = self::path($backup);
        if ($backup['kind'] === 'imagenes') {
            if (is_dir($path)) {
                Tools::deleteDirectory($path, true);
            }
        } elseif (file_exists($path)) {
            @unlink($path);
        }
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'josralimpieza_backup` WHERE `id_backup` = ' . (int) $backup['id_backup']
        );

        return true;
    }

    /**
     * Borra las copias que han pasado los días de retención. Se llama al abrir la
     * configuración y desde el automático.
     *
     * @return array copias borradas y bytes liberados
     */
    public static function purge($days = null)
    {
        if ($days === null) {
            $days = (int) JosralimpiezaCore::setting('RETENCION');
        }
        $days = max(1, (int) $days);
        $result = array('count' => 0, 'bytes' => 0);
        if (!JosralimpiezaCore::tableExists('josralimpieza_backup')) {
            return $result;
        }
        $limit = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josralimpieza_backup`
              WHERE `date_add` < \'' . pSQL($limit) . '\''
        );
        if (!is_array($rows)) {
            return $result;
        }
        foreach ($rows as $row) {
            $result['bytes'] += (float) $row['bytes'];
            if (self::remove((int) $row['id_backup'])) {
                ++$result['count'];
            }
        }
        if ($result['count']) {
            JosralimpiezaCore::addHistory(
                'Purga de copias antiguas',
                $result['count'] . ' copias de más de ' . $days . ' días borradas definitivamente',
                $result['count'],
                $result['bytes'],
                0,
                'automatico'
            );
        }

        return $result;
    }
}
