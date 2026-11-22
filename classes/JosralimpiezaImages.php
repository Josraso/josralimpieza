<?php
/**
 * Escáner de imágenes de producto huérfanas.
 *
 * Recorre img/p por lotes, con una cola en la base de datos, y clasifica lo que
 * encuentra en cuatro casos, todos ellos imágenes que la tienda ya no usa:
 *
 *  - archivo_sin_registro  el archivo está en img/p pero no hay ninguna imagen
 *                          con ese número en la base de datos;
 *  - producto_borrado      la imagen existe en la base de datos pero su producto
 *                          ya no está;
 *  - tamano_inexistente    es la miniatura de un tamaño de imagen que ya no
 *                          existe en la tienda;
 *  - registro_sin_archivo  la imagen está en la base de datos pero su archivo
 *                          original no está en el disco.
 *
 * Los archivos no se borran: se mueven a la cuarentena, de donde se pueden
 * devolver a su sitio. Lo que no encaja en ningún caso (archivos con otro
 * formato de nombre y las imágenes planas de las tiendas muy antiguas, que están
 * en la raíz de img/p) solo se cuenta, nunca se toca.
 *
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosralimpiezaImages
{
    /** Casos que se pueden retirar a la cuarentena (archivos) */
    public static function fileKinds()
    {
        return array(
            'archivo_sin_registro' => 'Archivos sin ninguna imagen en la base de datos',
            'producto_borrado' => 'Imágenes de productos que ya no existen',
            'tamano_inexistente' => 'Miniaturas de tamaños de imagen que ya no existen',
        );
    }

    /** Casos que se arreglan en la base de datos, no en el disco */
    public static function dbKinds()
    {
        return array(
            'registro_sin_archivo' => 'Imágenes de la base de datos cuyo archivo no está en el disco',
        );
    }

    /** Lo que solo se cuenta, nunca se toca */
    public static function infoKinds()
    {
        return array(
            'info_antiguo' => 'Imágenes en la raíz de img/p (formato de tiendas anteriores a la 1.5)',
            'info_desconocido' => 'Archivos que no siguen el formato de nombres de PrestaShop',
        );
    }

    public static function kindName($kind)
    {
        $all = array_merge(self::fileKinds(), self::dbKinds(), self::infoKinds());

        return isset($all[$kind]) ? $all[$kind] : $kind;
    }

    protected static function table()
    {
        return '`' . _DB_PREFIX_ . 'josralimpieza_scan`';
    }

    protected static function root()
    {
        return defined('_PS_PROD_IMG_DIR_') ? _PS_PROD_IMG_DIR_ : _PS_IMG_DIR_ . 'p/';
    }

    /* ------------------------------------------------------------------ */
    /* Escaneo                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Empieza un escáner nuevo: borra los hallazgos anteriores que no se hayan
     * retirado y pone la raíz de img/p en la cola.
     *
     * @return array|string
     */
    public static function scanInit()
    {
        if (!is_dir(self::root())) {
            return 'No existe la carpeta de imágenes de producto (' . self::root() . ').';
        }
        Db::getInstance()->execute('DELETE FROM ' . self::table() . ' WHERE `status` = 0');
        Configuration::updateGlobalValue(JosralimpiezaCore::key('SCAN_CURSOR'), 0);
        Configuration::updateGlobalValue(JosralimpiezaCore::key('SCAN_FASE'), 'carpetas');
        self::push('dir', '');

        return array('phase' => 'carpetas');
    }

    protected static function push($kind, $path, $risk = 0, $idImage = 0, $idProduct = 0, $bytes = 0)
    {
        Db::getInstance()->execute(
            'INSERT INTO ' . self::table() . ' (`kind`, `risk`, `path`, `id_image`, `id_product`, `bytes`, `status`, `date_add`)
             VALUES (\'' . pSQL($kind) . '\', ' . (int) $risk . ', \'' . pSQL($path) . '\', ' . (int) $idImage . ', '
                . (int) $idProduct . ', ' . (int) $bytes . ', 0, \'' . pSQL(date('Y-m-d H:i:s')) . '\')'
        );
    }

    /**
     * Tamaños de imagen de producto que existen ahora mismo en la tienda.
     */
    protected static function currentTypes()
    {
        $types = array();
        if (!JosralimpiezaCore::tableExists('image_type')) {
            return $types;
        }
        $rows = Db::getInstance()->executeS(
            'SELECT `name` FROM `' . _DB_PREFIX_ . 'image_type`'
            . (JosralimpiezaCore::columnExists('image_type', 'products') ? ' WHERE `products` = 1' : '')
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $types[Tools::strtolower($row['name'])] = true;
            }
        }

        return $types;
    }

    /**
     * Procesa un lote de la cola.
     *
     * @param int $limit carpetas (o filas de la base de datos) por lote
     *
     * @return array|string
     */
    public static function scanBatch($limit)
    {
        $phase = (string) Configuration::getGlobalValue(JosralimpiezaCore::key('SCAN_FASE'));
        if ($phase === 'registros') {
            return self::scanDbBatch($limit);
        }

        $dirs = Db::getInstance()->executeS(
            'SELECT `id_scan`, `path` FROM ' . self::table() . ' WHERE `kind` = \'dir\' AND `status` = 0 LIMIT ' . (int) $limit
        );
        if (!is_array($dirs)) {
            return 'No se pudo leer la cola del escáner: ' . Db::getInstance()->getMsgError();
        }
        if (!$dirs) {
            Configuration::updateGlobalValue(JosralimpiezaCore::key('SCAN_FASE'), 'registros');

            return self::scanDbBatch($limit);
        }

        $types = self::currentTypes();
        $keepWatermark = (int) JosralimpiezaCore::setting('MANTENER_WATERMARK');
        $candidates = array();
        $ids = array();
        $done = array();
        $scanned = 0;

        foreach ($dirs as $dir) {
            $done[] = (int) $dir['id_scan'];
            $relative = (string) $dir['path'];
            $absolute = self::root() . ($relative !== '' ? $relative . '/' : '');
            $depth = $relative === '' ? 0 : count(explode('/', $relative));
            $entries = @scandir($absolute);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $absolute . $entry;
                if (is_dir($path)) {
                    self::push('dir', ($relative !== '' ? $relative . '/' : '') . $entry);
                    continue;
                }
                if (!is_file($path)) {
                    continue;
                }
                ++$scanned;
                $bytes = (int) @filesize($path);
                $file = ($relative !== '' ? $relative . '/' : '') . $entry;

                // En la raíz de img/p están las imágenes de las tiendas anteriores a la 1.5: no se tocan
                if ($depth === 0) {
                    self::push('info_antiguo', $file, 9, 0, 0, $bytes);
                    continue;
                }
                if (!preg_match('/^([0-9]+)(?:-(.+))?\.(jpe?g|png|gif|webp|avif)$/i', $entry, $match)) {
                    self::push('info_desconocido', $file, 9, 0, 0, $bytes);
                    continue;
                }
                $suffix = isset($match[2]) ? Tools::strtolower($match[2]) : '';
                if ($keepWatermark && strpos($suffix, 'watermark') !== false) {
                    continue;
                }
                $idImage = (int) $match[1];
                $ids[$idImage] = true;
                $candidates[] = array('file' => $file, 'id' => $idImage, 'suffix' => $suffix, 'bytes' => $bytes);
            }
        }

        // Una sola consulta por lote: qué números de imagen existen y si su producto sigue ahí
        $known = array();
        if ($ids) {
            $rows = Db::getInstance()->executeS(
                'SELECT `i`.`id_image`, `i`.`id_product`, `p`.`id_product` AS `producto`
                   FROM `' . _DB_PREFIX_ . 'image` `i`
                   LEFT JOIN `' . _DB_PREFIX_ . 'product` `p` ON `p`.`id_product` = `i`.`id_product`
                  WHERE `i`.`id_image` IN (' . implode(', ', array_map('intval', array_keys($ids))) . ')'
            );
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $known[(int) $row['id_image']] = array(
                        'id_product' => (int) $row['id_product'],
                        'producto' => $row['producto'] !== null,
                    );
                }
            }
        }

        $found = 0;
        foreach ($candidates as $candidate) {
            if (!isset($known[$candidate['id']])) {
                self::push('archivo_sin_registro', $candidate['file'], 0, $candidate['id'], 0, $candidate['bytes']);
                ++$found;
                continue;
            }
            if (!$known[$candidate['id']]['producto']) {
                self::push('producto_borrado', $candidate['file'], 0, $candidate['id'], $known[$candidate['id']]['id_product'], $candidate['bytes']);
                ++$found;
                continue;
            }
            if ($candidate['suffix'] !== '' && !isset($types[$candidate['suffix']])) {
                self::push('tamano_inexistente', $candidate['file'], 0, $candidate['id'], $known[$candidate['id']]['id_product'], $candidate['bytes']);
                ++$found;
            }
        }

        if ($done) {
            Db::getInstance()->execute(
                'UPDATE ' . self::table() . ' SET `status` = 1 WHERE `id_scan` IN (' . implode(', ', $done) . ')'
            );
        }

        $pending = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE `kind` = \'dir\' AND `status` = 0'
        );

        return array(
            'phase' => 'carpetas',
            'dirs' => count($done),
            'files' => $scanned,
            'found' => $found,
            'pending' => $pending,
            'done' => false,
        );
    }

    /**
     * Segunda fase: imágenes de la base de datos cuyo archivo original no está.
     */
    protected static function scanDbBatch($limit)
    {
        $cursor = (int) Configuration::getGlobalValue(JosralimpiezaCore::key('SCAN_CURSOR'));
        $rows = Db::getInstance()->executeS(
            'SELECT `id_image`, `id_product` FROM `' . _DB_PREFIX_ . 'image`
              WHERE `id_image` > ' . $cursor . ' ORDER BY `id_image` ASC LIMIT ' . max(50, (int) $limit * 20)
        );
        if (!is_array($rows)) {
            return 'No se pudo leer la tabla de imágenes: ' . Db::getInstance()->getMsgError();
        }
        if (!$rows) {
            Configuration::updateGlobalValue(JosralimpiezaCore::key('SCAN_FASE'), 'terminado');

            return array('phase' => 'terminado', 'done' => true, 'pending' => 0);
        }

        $found = 0;
        $last = $cursor;
        foreach ($rows as $row) {
            $idImage = (int) $row['id_image'];
            $last = $idImage;
            if (self::imagePath($idImage) === '') {
                self::push('registro_sin_archivo', self::relativeImagePath($idImage), 1, $idImage, (int) $row['id_product'], 0);
                ++$found;
            }
        }
        Configuration::updateGlobalValue(JosralimpiezaCore::key('SCAN_CURSOR'), $last);

        return array(
            'phase' => 'registros',
            'rows' => count($rows),
            'found' => $found,
            'pending' => 1,
            'done' => false,
        );
    }

    /**
     * Ruta del archivo original de una imagen, con la carpeta por dígitos que usa
     * PrestaShop (img/p/1/2/3/123.jpg).
     */
    public static function imageFolder($idImage)
    {
        $idImage = (int) $idImage;
        if ($idImage <= 0) {
            return '';
        }
        $digits = (string) $idImage;
        $path = '';
        for ($i = 0; $i < Tools::strlen($digits); ++$i) {
            $path .= $digits[$i] . '/';
        }

        return $path;
    }

    public static function relativeImagePath($idImage)
    {
        $folder = self::imageFolder($idImage);

        return $folder === '' ? '' : $folder . (int) $idImage . '.jpg';
    }

    /**
     * Ruta del archivo original, probando las extensiones que puede tener (lo
     * normal es .jpg, pero no se da por supuesto). Cadena vacía si no está.
     */
    public static function imagePath($idImage)
    {
        $folder = self::imageFolder($idImage);
        if ($folder === '') {
            return '';
        }
        foreach (array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif') as $extension) {
            $path = self::root() . $folder . (int) $idImage . '.' . $extension;
            if (file_exists($path)) {
                return $path;
            }
        }

        return '';
    }

    /* ------------------------------------------------------------------ */
    /* Resumen                                                             */
    /* ------------------------------------------------------------------ */

    public static function summary()
    {
        $summary = array(
            'phase' => (string) Configuration::getGlobalValue(JosralimpiezaCore::key('SCAN_FASE')),
            'pending' => 0,
            'kinds' => array(),
            'total_files' => 0,
            'total_bytes' => 0,
        );
        if (!JosralimpiezaCore::tableExists('josralimpieza_scan')) {
            return $summary;
        }
        $fileKinds = self::fileKinds();
        $infoKinds = self::infoKinds();
        $rows = Db::getInstance()->executeS(
            'SELECT `kind`, COUNT(*) AS `cuantos`, SUM(`bytes`) AS `peso`
               FROM ' . self::table() . ' WHERE `status` = 0 GROUP BY `kind`'
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if ($row['kind'] === 'dir') {
                    $summary['pending'] = (int) $row['cuantos'];
                    continue;
                }
                $summary['kinds'][$row['kind']] = array(
                    'kind' => $row['kind'],
                    'name' => self::kindName($row['kind']),
                    'count' => (int) $row['cuantos'],
                    'bytes' => (float) $row['peso'],
                    'human' => JosralimpiezaCore::humanBytes($row['peso']),
                    'info' => array_key_exists($row['kind'], $infoKinds),
                );
                if (array_key_exists($row['kind'], $fileKinds)) {
                    $summary['total_files'] += (int) $row['cuantos'];
                    $summary['total_bytes'] += (float) $row['peso'];
                }
            }
        }
        $summary['total_human'] = JosralimpiezaCore::humanBytes($summary['total_bytes']);

        return $summary;
    }

    /**
     * Unos cuantos ejemplos de un caso, para verlos antes de retirar nada.
     */
    public static function examples($kind, $limit = 15)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `path`, `id_image`, `id_product`, `bytes` FROM ' . self::table() . '
              WHERE `status` = 0 AND `kind` = \'' . pSQL($kind) . '\' LIMIT ' . (int) $limit
        );
        if (!is_array($rows)) {
            return array();
        }
        foreach ($rows as &$row) {
            $row['human'] = JosralimpiezaCore::humanBytes($row['bytes']);
        }
        unset($row);

        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* Retirar a la cuarentena                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Mueve a la cuarentena un lote de archivos de los casos indicados.
     *
     * @param int   $idBackup lote de cuarentena abierto
     * @param array $kinds    casos a retirar
     * @param int   $limit    archivos de este lote
     *
     * @return array|string
     */
    public static function quarantineBatch($idBackup, array $kinds, $limit)
    {
        $fileKinds = self::fileKinds();
        $allowed = array();
        foreach ($kinds as $kind) {
            if (array_key_exists($kind, $fileKinds)) {
                $allowed[] = '\'' . pSQL($kind) . '\'';
            }
        }
        if (!$allowed) {
            return 'No se ha marcado ningún caso de archivos para retirar.';
        }

        $rows = Db::getInstance()->executeS(
            'SELECT `id_scan`, `path`, `bytes` FROM ' . self::table() . '
              WHERE `status` = 0 AND `kind` IN (' . implode(', ', $allowed) . ') LIMIT ' . (int) $limit
        );
        if (!is_array($rows)) {
            return 'No se pudo leer la lista de archivos: ' . Db::getInstance()->getMsgError();
        }
        if (!$rows) {
            return array('moved' => 0, 'bytes' => 0, 'done' => true);
        }

        $backup = JosralimpiezaBackup::get($idBackup);
        if (!$backup || $backup['kind'] !== 'imagenes') {
            return 'El lote de cuarentena número ' . (int) $idBackup . ' no existe: no se mueve nada.';
        }

        $moved = 0;
        $bytes = 0;
        $skipped = 0;
        $firstError = '';
        $handled = array();
        foreach ($rows as $row) {
            $absolute = self::root() . $row['path'];
            $result = JosralimpiezaBackup::moveFile($backup, $absolute);
            if (is_int($result)) {
                ++$moved;
                $bytes += $result;
                $handled[] = (int) $row['id_scan'];
            } elseif (!file_exists($absolute)) {
                // Ya no estaba: se da por hecho
                ++$skipped;
                $handled[] = (int) $row['id_scan'];
            } else {
                if ($firstError === '') {
                    $firstError = $result;
                }
                break;
            }
        }

        // Una sola consulta por lote, no una por archivo
        JosralimpiezaBackup::addItems($idBackup, $moved, $bytes);
        if ($handled) {
            Db::getInstance()->execute(
                'UPDATE ' . self::table() . ' SET `status` = 1 WHERE `id_scan` IN (' . implode(', ', $handled) . ')'
            );
        }
        if ($firstError !== '' && !$moved) {
            return $firstError;
        }

        return array(
            'moved' => $moved,
            'skipped' => $skipped,
            'bytes' => $bytes,
            'error' => $firstError,
            'done' => count($rows) < (int) $limit,
        );
    }

    /**
     * Borra de la base de datos un lote de imágenes cuyo archivo no está en el
     * disco, guardando antes las filas de image, image_lang e image_shop.
     *
     * @return array|string
     */
    public static function cleanMissingBatch($idBackup, $limit)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_scan`, `id_image` FROM ' . self::table() . '
              WHERE `status` = 0 AND `kind` = \'registro_sin_archivo\' LIMIT ' . (int) $limit
        );
        if (!is_array($rows)) {
            return 'No se pudo leer la lista de imágenes: ' . Db::getInstance()->getMsgError();
        }
        if (!$rows) {
            return array('deleted' => 0, 'done' => true);
        }

        $ids = array();
        $handled = array();
        foreach ($rows as $row) {
            $ids[] = (int) $row['id_image'];
            $handled[] = (int) $row['id_scan'];
        }
        $list = implode(', ', $ids);

        // Copia previa de las tres tablas antes de tocar nada
        foreach (array('image_lang', 'image_shop', 'image') as $table) {
            if (!JosralimpiezaCore::tableExists($table)) {
                continue;
            }
            $saved = Db::getInstance()->executeS(
                'SELECT * FROM `' . _DB_PREFIX_ . bqSQL($table) . '` WHERE `id_image` IN (' . $list . ')'
            );
            if (!is_array($saved)) {
                return 'No se pudo leer ' . _DB_PREFIX_ . $table . ': ' . Db::getInstance()->getMsgError();
            }
            if ($saved) {
                $result = JosralimpiezaBackup::append($idBackup, $table, $saved);
                if ($result !== true) {
                    return $result;
                }
            }
        }

        $deleted = 0;
        foreach (array('image_lang', 'image_shop', 'image') as $table) {
            if (!JosralimpiezaCore::tableExists($table)) {
                continue;
            }
            if (!Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . bqSQL($table) . '` WHERE `id_image` IN (' . $list . ')'
            )) {
                return 'No se pudo borrar de ' . _DB_PREFIX_ . $table . ': ' . Db::getInstance()->getMsgError();
            }
            $deleted += (int) Db::getInstance()->Affected_Rows();
        }

        Db::getInstance()->execute(
            'UPDATE ' . self::table() . ' SET `status` = 1 WHERE `id_scan` IN (' . implode(', ', $handled) . ')'
        );

        return array('deleted' => $deleted, 'images' => count($ids), 'done' => count($rows) < (int) $limit);
    }

    /**
     * Peso de la carpeta de imágenes de producto y de la cuarentena.
     */
    public static function diskUsage()
    {
        $products = JosralimpiezaCore::dirSize(self::root());
        $quarantine = JosralimpiezaCore::dirSize(JosralimpiezaCore::quarantineDir());

        return array(
            'productos' => $products,
            'productos_human' => JosralimpiezaCore::humanBytes($products['bytes']),
            'cuarentena' => $quarantine,
            'cuarentena_human' => JosralimpiezaCore::humanBytes($quarantine['bytes']),
        );
    }
}
