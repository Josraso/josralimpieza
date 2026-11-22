<?php
/**
 * Limpieza de base de datos e imágenes.
 *
 * Aligera la tienda en cuatro pasos, siempre viendo antes qué se va a tocar y
 * con copia previa de todo lo que se borra:
 *
 *  1. Diagnóstico: cuánto pesa cada tabla, cuánto espacio hay desperdiciado y
 *     cuánto ocupan las imágenes de producto.
 *  2. Base de datos: registros, estadísticas, carritos abandonados y filas
 *     huérfanas, por apartados y con la antigüedad que se quiera.
 *  3. Imágenes: busca las imágenes de producto que la tienda ya no usa y las
 *     retira a una cuarentena de la que se pueden devolver.
 *  4. Optimizar: recupera el espacio desperdiciado tabla por tabla.
 *
 * Todo va por AJAX y en lotes, con pausa y reanudación, para no cargar el
 * servidor. El automático (opcional, por URL de cron) solo toca los apartados de
 * riesgo cero y manda un informe por correo.
 *
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/JosralimpiezaUpdater.php';
require_once dirname(__FILE__) . '/classes/JosralimpiezaCore.php';
require_once dirname(__FILE__) . '/classes/JosralimpiezaBackup.php';
require_once dirname(__FILE__) . '/classes/JosralimpiezaDb.php';
require_once dirname(__FILE__) . '/classes/JosralimpiezaImages.php';

class Josralimpieza extends Module
{
    public function __construct()
    {
        $this->name = 'josralimpieza';
        $this->tab = 'administration';
        $this->version = '1.0.5';
        $this->author = 'josra';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('Limpieza de base de datos e imágenes');
        $this->description = $this->l('Aligera la tienda: limpia registros y filas inútiles de la base de datos, optimiza tablas y retira las imágenes de producto que ya no se usan. Siempre por lotes AJAX, viendo antes qué se va a tocar y con copia previa de todo.');
        $this->confirmUninstall = $this->l('Se borrarán las tablas del módulo (cola, copias registradas e historial). Los archivos de las copias y de la cuarentena se quedan en la carpeta del módulo.');
    }

    /* ------------------------------------------------------------------ */
    /* Instalación                                                         */
    /* ------------------------------------------------------------------ */

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        if (!$this->installDb()) {
            return false;
        }
        if (!$this->getUpdater()->registerHooks()) {
            return false;
        }
        foreach (JosralimpiezaCore::defaults() as $name => $value) {
            if (Configuration::getGlobalValue(JosralimpiezaCore::key($name)) === false) {
                Configuration::updateGlobalValue(JosralimpiezaCore::key($name), $value);
            }
        }
        JosralimpiezaCore::ensureStorage();

        return true;
    }

    public function uninstall()
    {
        $this->getUpdater()->notifyUninstall();

        foreach (array('josralimpieza_scan', 'josralimpieza_backup', 'josralimpieza_history') as $table) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . bqSQL($table) . '`');
        }
        foreach (array_keys(JosralimpiezaCore::defaults()) as $name) {
            Configuration::deleteByName(JosralimpiezaCore::key($name));
        }
        foreach (array('SCAN_CURSOR', 'SCAN_FASE', 'CRON_ULTIMO', 'CRON_RESUMEN') as $name) {
            Configuration::deleteByName(JosralimpiezaCore::key($name));
        }
        Configuration::deleteByName('JOSRALIMPIEZA_UPD_TS');
        Configuration::deleteByName('JOSRALIMPIEZA_UPD_INFO');
        Configuration::deleteByName('JOSRALIMPIEZA_UPD_SU');
        Configuration::deleteByName('JOSRALIMPIEZA_UPD_LT');

        return parent::uninstall();
    }

    /**
     * Crea lo que falte. Se llama al instalar y cada vez que se abre la
     * configuración, así que el módulo se repara solo si se sube por FTP.
     */
    public function installDb()
    {
        $engine = defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB';
        $queries = array();

        $queries[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'josralimpieza_scan` (
            `id_scan` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `kind` varchar(32) NOT NULL,
            `risk` tinyint(1) unsigned NOT NULL DEFAULT \'0\',
            `path` varchar(255) NOT NULL DEFAULT \'\',
            `id_image` int(10) unsigned NOT NULL DEFAULT \'0\',
            `id_product` int(10) unsigned NOT NULL DEFAULT \'0\',
            `bytes` bigint(20) unsigned NOT NULL DEFAULT \'0\',
            `status` tinyint(1) unsigned NOT NULL DEFAULT \'0\',
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id_scan`),
            KEY `kind_status` (`kind`, `status`)
        ) ENGINE=' . $engine;

        $queries[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'josralimpieza_backup` (
            `id_backup` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `kind` varchar(16) NOT NULL,
            `label` varchar(190) NOT NULL,
            `filename` varchar(190) NOT NULL,
            `items` int(10) unsigned NOT NULL DEFAULT \'0\',
            `bytes` bigint(20) unsigned NOT NULL DEFAULT \'0\',
            `restored` tinyint(1) unsigned NOT NULL DEFAULT \'0\',
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id_backup`),
            KEY `date_add` (`date_add`)
        ) ENGINE=' . $engine;

        $queries[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'josralimpieza_history` (
            `id_history` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `action` varchar(64) NOT NULL,
            `detail` text NOT NULL,
            `items` int(10) unsigned NOT NULL DEFAULT \'0\',
            `bytes` bigint(20) unsigned NOT NULL DEFAULT \'0\',
            `source` varchar(16) NOT NULL DEFAULT \'manual\',
            `id_backup` int(10) unsigned NOT NULL DEFAULT \'0\',
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id_history`),
            KEY `date_add` (`date_add`)
        ) ENGINE=' . $engine;

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Actualizaciones                                                     */
    /* ------------------------------------------------------------------ */

    public function getUpdater()
    {
        return new JosralimpiezaUpdater($this);
    }

    /** Aviso periódico al servidor de actualizaciones (solo en el back-office) */
    public function hookActionAdminControllerSetMedia($params)
    {
        $this->getUpdater()->maybePing();
    }

    /** PS 8.1+: la versión nueva aparece en Gestor de módulos > Actualizaciones */
    public function hookActionListModules($params)
    {
        return $this->getUpdater()->getNativeModuleList();
    }

    /* ------------------------------------------------------------------ */
    /* URLs                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * URL completa de la configuración: en PS 8/9 la configuración se abre por
     * ruta Symfony, así que una dirección relativa acabaría en un 404.
     */
    public function configUrl($extra = '')
    {
        $adminDir = defined('_PS_ADMIN_DIR_') ? basename(_PS_ADMIN_DIR_) : '';

        return Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . ($adminDir !== '' ? $adminDir . '/' : '')
            . 'index.php?controller=AdminModules&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules') . $extra;
    }

    public function cronToken()
    {
        return md5(_COOKIE_KEY_ . 'josralimpieza');
    }

    public function cronUrl()
    {
        return Tools::getShopDomainSsl(true) . __PS_BASE_URI__
            . 'index.php?fc=module&module=josralimpieza&controller=cron&token=' . $this->cronToken();
    }

    /* ------------------------------------------------------------------ */
    /* Configuración                                                       */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        // El AJAX se atiende antes de cualquier salida
        if (Tools::getValue('josralimpieza_ajax')) {
            $this->ajax();
        }

        $output = '';
        $this->installDb();
        $storage = JosralimpiezaCore::ensureStorage();
        if ($storage !== true) {
            $output .= $this->displayError($storage);
        }

        $updater = $this->getUpdater();
        $output .= $updater->processRequest();

        if (Tools::isSubmit('josralimpieza_guardar')) {
            $output .= $this->saveSettings();
        }

        // Las copias que han pasado la retención se borran solas
        JosralimpiezaBackup::purge();

        $content = $this->renderContent();
        $info = $updater->getInfo();
        $panel = $updater->renderPanel();

        return !empty($info['update_available'])
            ? $output . $panel . $content
            : $output . $content . $panel;
    }

    protected function saveSettings()
    {
        $values = array();
        foreach (array_keys(JosralimpiezaCore::defaults()) as $name) {
            $field = 'JOSRALIMPIEZA_' . $name;
            // Una casilla desmarcada no llega en el POST: se guarda como 0
            if (in_array($name, array('MANTENER_WATERMARK', 'CORREO_ACTIVO', 'CRON_REGISTROS', 'CRON_CACHE', 'CRON_HUERFANOS'), true)) {
                $values[$name] = (int) Tools::getValue($field) ? 1 : 0;
                continue;
            }
            if (Tools::getValue($field) !== false) {
                $values[$name] = Tools::getValue($field);
            }
        }
        if (isset($values['CORREO']) && trim($values['CORREO']) !== '' && !Validate::isEmail($values['CORREO'])) {
            return $this->displayError($this->l('El correo para el informe no es válido: ') . Tools::safeOutput($values['CORREO']));
        }
        JosralimpiezaCore::saveSettings($values);

        return $this->displayConfirmation($this->l('Ajustes guardados.'));
    }

    /**
     * Formulario de ajustes con los componentes nativos de la versión instalada.
     */
    protected function renderSettingsForm()
    {
        $settings = JosralimpiezaCore::settings();

        $hours = array();
        for ($hour = 0; $hour < 24; ++$hour) {
            $hours[] = array('id' => $hour, 'name' => sprintf('A las %02d:00', $hour));
        }

        $fields = array();
        $fields[] = array(
            'form' => array(
                'legend' => array('title' => $this->l('Qué se considera antiguo'), 'icon' => 'icon-time'),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Registros y estadísticas'),
                        'name' => 'JOSRALIMPIEZA_ANTIGUEDAD_REGISTROS',
                        'suffix' => $this->l('meses'),
                        'class' => 'fixed-width-sm',
                        'required' => true,
                        'desc' => $this->l('Se limpia lo anterior a estos meses. Con 0 se limpia todo.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Carritos e invitados'),
                        'name' => 'JOSRALIMPIEZA_ANTIGUEDAD_CARRITOS',
                        'suffix' => $this->l('meses'),
                        'class' => 'fixed-width-sm',
                        'required' => true,
                        'desc' => $this->l('Los carritos que ya son pedido no se tocan nunca, tengan la edad que tengan.'),
                    ),
                ),
                'submit' => array('title' => $this->l('Guardar'), 'name' => 'josralimpieza_guardar'),
            ),
        );
        $fields[] = array(
            'form' => array(
                'legend' => array('title' => $this->l('Copias y lotes'), 'icon' => 'icon-hdd'),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Guardar las copias'),
                        'name' => 'JOSRALIMPIEZA_RETENCION',
                        'suffix' => $this->l('días'),
                        'class' => 'fixed-width-sm',
                        'required' => true,
                        'desc' => $this->l('Pasados estos días, las copias y la cuarentena se borran definitivamente, solas.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Filas por lote'),
                        'name' => 'JOSRALIMPIEZA_LOTE_BD',
                        'class' => 'fixed-width-sm',
                        'required' => true,
                        'desc' => $this->l('Entre 50 y 50000. Cada llamada encadena lotes hasta agotar el tiempo que da el servidor, así que con 2000 va bien. Si el servidor va justo, bájalo.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Carpetas por lote'),
                        'name' => 'JOSRALIMPIEZA_LOTE_IMG',
                        'class' => 'fixed-width-sm',
                        'required' => true,
                        'desc' => $this->l('Carpetas de img/p que se revisan en cada paso del escáner. Entre 5 y 500.'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('No tocar las marcas de agua'),
                        'name' => 'JOSRALIMPIEZA_MANTENER_WATERMARK',
                        'is_bool' => true,
                        'desc' => $this->l('Deja fuera del escáner los archivos con «watermark» en el nombre.'),
                        'values' => array(
                            array('id' => 'wm_on', 'value' => 1, 'label' => $this->l('Sí')),
                            array('id' => 'wm_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                ),
                'submit' => array('title' => $this->l('Guardar'), 'name' => 'josralimpieza_guardar'),
            ),
        );
        $fields[] = array(
            'form' => array(
                'legend' => array('title' => $this->l('Limpieza automática'), 'icon' => 'icon-refresh'),
                'description' => $this->l('El automático solo toca apartados de riesgo cero: nunca borra imágenes, nunca optimiza tablas y nunca toca carritos ni clientes. Necesita que llames a la URL de cron de la pestaña Automático.'),
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('Cada cuánto'),
                        'name' => 'JOSRALIMPIEZA_CRON_FRECUENCIA',
                        'options' => array(
                            'query' => array(
                                array('id' => 'off', 'name' => $this->l('Desactivada')),
                                array('id' => 'diaria', 'name' => $this->l('Todos los días')),
                                array('id' => 'semanal', 'name' => $this->l('Una vez a la semana (los lunes)')),
                                array('id' => 'mensual', 'name' => $this->l('Una vez al mes (el día 1)')),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('A qué hora'),
                        'name' => 'JOSRALIMPIEZA_CRON_HORA',
                        'options' => array('query' => $hours, 'id' => 'id', 'name' => 'name'),
                        'desc' => $this->l('Hora de la tienda (Europe/Madrid si así está configurada). La limpieza se hace en la primera llamada de cron a partir de esa hora.'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Registros y estadísticas'),
                        'name' => 'JOSRALIMPIEZA_CRON_REGISTROS',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'cr_on', 'value' => 1, 'label' => $this->l('Sí')),
                            array('id' => 'cr_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Caché de la base de datos'),
                        'name' => 'JOSRALIMPIEZA_CRON_CACHE',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'cc_on', 'value' => 1, 'label' => $this->l('Sí')),
                            array('id' => 'cc_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Filas huérfanas seguras'),
                        'name' => 'JOSRALIMPIEZA_CRON_HUERFANOS',
                        'is_bool' => true,
                        'desc' => $this->l('Solo las de riesgo cero: textos e índices de imágenes, combinaciones y palabras del buscador que ya no existen.'),
                        'values' => array(
                            array('id' => 'ch_on', 'value' => 1, 'label' => $this->l('Sí')),
                            array('id' => 'ch_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Informe por correo'),
                        'name' => 'JOSRALIMPIEZA_CORREO_ACTIVO',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'mail_on', 'value' => 1, 'label' => $this->l('Sí')),
                            array('id' => 'mail_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Correo del informe'),
                        'name' => 'JOSRALIMPIEZA_CORREO',
                        'desc' => $this->l('Si se deja vacío se usa el correo de la tienda.'),
                    ),
                ),
                'submit' => array('title' => $this->l('Guardar'), 'name' => 'josralimpieza_guardar'),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->table = $this->table;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->configUrl();
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = 0;
        $helper->title = $this->displayName;
        $helper->submit_action = 'josralimpieza_guardar';
        $helper->fields_value = array(
            'JOSRALIMPIEZA_ANTIGUEDAD_REGISTROS' => $settings['ANTIGUEDAD_REGISTROS'],
            'JOSRALIMPIEZA_ANTIGUEDAD_CARRITOS' => $settings['ANTIGUEDAD_CARRITOS'],
            'JOSRALIMPIEZA_RETENCION' => $settings['RETENCION'],
            'JOSRALIMPIEZA_LOTE_BD' => $settings['LOTE_BD'],
            'JOSRALIMPIEZA_LOTE_IMG' => $settings['LOTE_IMG'],
            'JOSRALIMPIEZA_MANTENER_WATERMARK' => $settings['MANTENER_WATERMARK'],
            'JOSRALIMPIEZA_CRON_FRECUENCIA' => $settings['CRON_FRECUENCIA'],
            'JOSRALIMPIEZA_CRON_HORA' => $settings['CRON_HORA'],
            'JOSRALIMPIEZA_CRON_REGISTROS' => $settings['CRON_REGISTROS'],
            'JOSRALIMPIEZA_CRON_CACHE' => $settings['CRON_CACHE'],
            'JOSRALIMPIEZA_CRON_HUERFANOS' => $settings['CRON_HUERFANOS'],
            'JOSRALIMPIEZA_CORREO_ACTIVO' => $settings['CORREO_ACTIVO'],
            'JOSRALIMPIEZA_CORREO' => $settings['CORREO'],
        );

        return $helper->generateForm($fields);
    }

    /**
     * Pinta la configuración entera.
     */
    protected function renderContent()
    {
        $settings = JosralimpiezaCore::settings();
        $groups = JosralimpiezaDb::groups();
        $last = (int) Configuration::getGlobalValue(JosralimpiezaCore::key('CRON_ULTIMO'));

        $this->context->smarty->assign(array(
            'jl_url' => $this->configUrl(),
            'jl_ajax' => $this->configUrl('&josralimpieza_ajax=1'),
            'jl_groups' => $groups,
            'jl_settings' => $settings,
            'jl_form' => $this->renderSettingsForm(),
            'jl_backups_html' => $this->renderBackups(),
            'jl_history_html' => $this->renderHistory(),
            'jl_images_html' => $this->renderImageSummary(JosralimpiezaImages::summary(), JosralimpiezaImages::diskUsage()),
            'jl_prefijo' => _DB_PREFIX_,
            'jl_cuarentena' => str_replace(_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR, '', JosralimpiezaCore::quarantineDir()),
            'jl_cron_url' => $this->cronUrl(),
            'jl_cron_last' => $last ? date('d/m/Y H:i', $last) : '',
            'jl_cron_summary' => (string) Configuration::getGlobalValue(JosralimpiezaCore::key('CRON_RESUMEN')),
            'jl_version' => $this->version,
            'jl_multistore' => Shop::isFeatureActive() ? 1 : 0,
            'jl_module_dir' => $this->_path,
        ));

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/configure.tpl');
    }

    /* ------------------------------------------------------------------ */
    /* AJAX                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Todas las acciones van por aquí, dentro de la propia configuración del
     * módulo: así no hace falta ningún controlador de admin ni pestaña oculta.
     * No devuelve nunca: termina la petición con la respuesta en JSON.
     */
    protected function ajax()
    {
        @set_time_limit(0);
        while (ob_get_level()) {
            ob_end_clean();
        }
        $action = Tools::getValue('accion');
        $meses = Tools::getValue('meses');
        if ($meses !== false && $meses !== '' && is_numeric($meses)) {
            JosralimpiezaDb::$months = max(0, (int) $meses);
        }
        $response = array('ok' => false, 'error' => 'Acción desconocida: ' . Tools::safeOutput($action));

        try {
            switch ($action) {
                case 'diagnostico':
                    $response = $this->ajaxDiagnostico();
                    break;
                case 'contar':
                    $response = $this->ajaxContar();
                    break;
                case 'ver':
                    $response = $this->ajaxVer();
                    break;
                case 'abrir_copia':
                    $response = $this->ajaxAbrirCopia();
                    break;
                case 'cerrar_copia':
                    JosralimpiezaBackup::finish((int) Tools::getValue('copia'));
                    $response = array('ok' => true);
                    break;
                case 'limpiar':
                    $response = $this->ajaxLimpiar();
                    break;
                case 'img_init':
                    $response = $this->respond(JosralimpiezaImages::scanInit());
                    break;
                case 'img_lote':
                    $response = $this->respond(JosralimpiezaImages::scanBatch((int) JosralimpiezaCore::setting('LOTE_IMG')));
                    break;
                case 'img_resumen':
                    $response = $this->ajaxImgResumen();
                    break;
                case 'img_ejemplos':
                    $response = $this->ajaxImgEjemplos();
                    break;
                case 'img_retirar':
                    $response = $this->ajaxImgRetirar();
                    break;
                case 'img_registros':
                    $response = $this->ajaxImgRegistros();
                    break;
                case 'tablas':
                    $response = $this->ajaxTablas();
                    break;
                case 'optimizar':
                    $response = $this->respond(JosralimpiezaCore::optimizeTable(Tools::getValue('tabla')));
                    break;
                case 'restaurar':
                    $response = $this->respond(JosralimpiezaBackup::restore((int) Tools::getValue('copia')));
                    break;
                case 'borrar_copia':
                    $response = array('ok' => (bool) JosralimpiezaBackup::remove((int) Tools::getValue('copia')));
                    if (!$response['ok']) {
                        $response['error'] = 'Esa copia ya no existe.';
                    }
                    break;
                case 'copias':
                    $response = array('ok' => true, 'html' => $this->renderBackups());
                    break;
                case 'historial':
                    $response = array('ok' => true, 'html' => $this->renderHistory());
                    break;
            }
        } catch (Exception $e) {
            $response = array('ok' => false, 'error' => 'Error inesperado: ' . $e->getMessage());
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response);
        die();
    }

    /**
     * Segundos que puede trabajar una llamada AJAX seguidos. Así cada petición
     * hace muchos lotes en vez de uno, que es lo que hacía que una tabla de
     * millones de filas tardara siglos: antes se gastaba casi todo el tiempo en
     * ir y volver al servidor, no en borrar.
     */
    protected function ajaxBudget()
    {
        $limit = (int) ini_get('max_execution_time');
        $budget = $limit <= 0 ? 20 : max(5, min(20, $limit - 10));

        return $budget;
    }

    /**
     * Convierte el resultado de un motor (array o motivo del fallo) en respuesta.
     */
    protected function respond($result)
    {
        if (is_array($result)) {
            return array_merge(array('ok' => true), $result);
        }

        return array('ok' => false, 'error' => (string) $result);
    }

    protected function ajaxDiagnostico()
    {
        $tables = JosralimpiezaCore::tableStatus();
        $size = 0;
        $free = 0;
        foreach ($tables as $table) {
            $size += $table['size'];
            $free += $table['free'];
        }
        $disk = JosralimpiezaImages::diskUsage();
        $backups = JosralimpiezaBackup::listAll(500);
        $backupBytes = 0;
        foreach ($backups as $backup) {
            $backupBytes += (float) $backup['bytes'];
        }

        return array(
            'ok' => true,
            'tablas' => count($tables),
            'peso' => JosralimpiezaCore::humanBytes($size),
            'desperdiciado' => JosralimpiezaCore::humanBytes($free),
            'desperdiciado_bytes' => $free,
            'imagenes' => $disk['productos_human'],
            'imagenes_archivos' => (int) $disk['productos']['files'],
            'cuarentena' => $disk['cuarentena_human'],
            'copias' => count($backups),
            'copias_peso' => JosralimpiezaCore::humanBytes($backupBytes),
            'html' => $this->renderTables($tables, 15),
        );
    }

    protected function ajaxContar()
    {
        $item = JosralimpiezaDb::findItem(Tools::getValue('item'));
        if (!$item) {
            return array('ok' => false, 'error' => 'Ese apartado no existe en esta tienda.');
        }
        $count = JosralimpiezaDb::countItem($item);
        if (!is_int($count)) {
            return array('ok' => false, 'error' => (string) $count);
        }

        return array(
            'ok' => true,
            'item' => $item['id'],
            'filas' => $count,
            // Para que se vea de un vistazo "0 de 6 millones": casi siempre es la fecha
            'total' => JosralimpiezaCore::approxRows($item['table']),
        );
    }

    protected function ajaxVer()
    {
        $item = JosralimpiezaDb::findItem(Tools::getValue('item'));
        if (!$item) {
            return array('ok' => false, 'error' => 'Ese apartado no existe en esta tienda.');
        }
        $rows = JosralimpiezaDb::previewItem($item, 15);
        if (!is_array($rows)) {
            return array('ok' => false, 'error' => (string) $rows);
        }
        if (!$rows) {
            return array('ok' => true, 'html' => '<p class="alert alert-success">'
                . $this->l('No hay nada que borrar en este apartado.') . '</p>');
        }

        $html = '<p>' . sprintf($this->l('Primeras %d filas de %s que se borrarían (y que quedarían guardadas en la copia previa):'), count($rows), '<strong>' . Tools::safeOutput(_DB_PREFIX_ . $item['table']) . '</strong>') . '</p>';
        $html .= '<div class="table-responsive"><table class="table"><thead><tr>';
        foreach (array_keys($rows[0]) as $column) {
            $html .= '<th>' . Tools::safeOutput($column) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>' . ($value === null ? '<em>NULL</em>' : Tools::safeOutput($value)) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return array('ok' => true, 'html' => $html);
    }

    protected function ajaxAbrirCopia()
    {
        $kind = Tools::getValue('tipo') === 'imagenes' ? 'imagenes' : 'bd';
        $label = Tools::substr((string) Tools::getValue('titulo'), 0, 180);
        if ($label === '') {
            $label = $kind === 'imagenes' ? 'Imágenes retiradas' : 'Limpieza de base de datos';
        }
        $id = JosralimpiezaBackup::start($kind, $label);
        if (!is_int($id)) {
            return array('ok' => false, 'error' => (string) $id);
        }

        return array('ok' => true, 'copia' => $id);
    }

    protected function ajaxLimpiar()
    {
        $item = JosralimpiezaDb::findItem(Tools::getValue('item'));
        if (!$item) {
            return array('ok' => false, 'error' => 'Ese apartado no existe en esta tienda.');
        }
        $idBackup = (int) Tools::getValue('copia');
        $sinCopia = (int) Tools::getValue('sincopia');
        if ($item['backup'] && !$idBackup && !$sinCopia) {
            return array('ok' => false, 'error' => 'No hay copia previa abierta: no se borra nada.');
        }

        // Muchos lotes en una sola llamada, hasta agotar el tiempo que da el servidor
        $batch = (int) JosralimpiezaCore::setting('LOTE_BD');
        $budget = $this->ajaxBudget();
        $start = microtime(true);
        $deleted = 0;
        $done = false;
        $truncated = false;
        $lotes = 0;
        do {
            $result = JosralimpiezaDb::cleanItem($item, $batch, $idBackup);
            if (!is_array($result)) {
                if ($deleted) {
                    JosralimpiezaCore::addHistory('Limpieza de base de datos', $item['name'] . ' (' . _DB_PREFIX_ . $item['table'] . ')', $deleted, 0, $idBackup);
                }

                return array('ok' => false, 'error' => (string) $result, 'deleted' => $deleted);
            }
            $deleted += (int) $result['deleted'];
            $done = !empty($result['done']);
            $truncated = !empty($result['truncated']);
            ++$lotes;
        } while (!$done && (microtime(true) - $start) < $budget);

        if ($truncated) {
            JosralimpiezaCore::addHistory('Vaciar tabla', $item['name'] . ' (' . _DB_PREFIX_ . $item['table'] . ')', 0, 0, 0);
        } elseif ($deleted) {
            JosralimpiezaCore::addHistory('Limpieza de base de datos', $item['name'] . ' (' . _DB_PREFIX_ . $item['table'] . ')', $deleted, 0, $idBackup);
        }

        return array(
            'ok' => true,
            'item' => $item['id'],
            'deleted' => $deleted,
            'done' => $done,
            'truncated' => $truncated,
            'lotes' => $lotes,
            'segundos' => round(microtime(true) - $start, 1),
        );
    }

    protected function ajaxImgResumen()
    {
        $summary = JosralimpiezaImages::summary();
        $disk = JosralimpiezaImages::diskUsage();

        return array(
            'ok' => true,
            'resumen' => $summary,
            'html' => $this->renderImageSummary($summary, $disk),
            'pendiente' => (int) $summary['pending'],
            'fase' => $summary['phase'],
        );
    }

    protected function ajaxImgEjemplos()
    {
        $kind = (string) Tools::getValue('caso');
        $rows = JosralimpiezaImages::examples($kind, 15);
        if (!$rows) {
            return array('ok' => true, 'html' => '<p class="alert alert-success">' . $this->l('No queda nada de este caso.') . '</p>');
        }
        $html = '<p>' . sprintf($this->l('Primeros %d de %s:'), count($rows), '<strong>' . Tools::safeOutput(JosralimpiezaImages::kindName($kind)) . '</strong>') . '</p>';
        $html .= '<div class="table-responsive"><table class="table"><thead><tr>'
            . '<th>' . $this->l('Archivo (dentro de img/p)') . '</th>'
            . '<th>' . $this->l('Nº de imagen') . '</th>'
            . '<th>' . $this->l('Nº de producto') . '</th>'
            . '<th>' . $this->l('Peso') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . Tools::safeOutput($row['path']) . '</td>'
                . '<td>' . (int) $row['id_image'] . '</td>'
                . '<td>' . ((int) $row['id_product'] ? (int) $row['id_product'] : '—') . '</td>'
                . '<td>' . Tools::safeOutput($row['human']) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        return array('ok' => true, 'html' => $html);
    }

    protected function ajaxImgRetirar()
    {
        $idBackup = (int) Tools::getValue('copia');
        if (!$idBackup) {
            return array('ok' => false, 'error' => 'No hay lote de cuarentena abierto: no se mueve nada.');
        }
        $kinds = Tools::getValue('casos');
        if (!is_array($kinds)) {
            $kinds = array_filter(explode(',', (string) $kinds));
        }
        $budget = $this->ajaxBudget();
        $start = microtime(true);
        $moved = 0;
        $bytes = 0;
        $skipped = 0;
        $done = false;
        $error = '';
        do {
            $result = JosralimpiezaImages::quarantineBatch($idBackup, $kinds, 200);
            if (!is_array($result)) {
                $error = (string) $result;
                break;
            }
            $moved += (int) $result['moved'];
            $bytes += (float) $result['bytes'];
            $skipped += (int) $result['skipped'];
            $done = !empty($result['done']);
            if (!empty($result['error'])) {
                $error = $result['error'];
                break;
            }
        } while (!$done && (microtime(true) - $start) < $budget);

        if ($moved) {
            JosralimpiezaCore::addHistory('Imágenes a la cuarentena', implode(', ', $kinds), $moved, $bytes, $idBackup);
        }
        if ($error !== '' && !$moved) {
            return array('ok' => false, 'error' => $error);
        }

        return array('ok' => true, 'moved' => $moved, 'bytes' => $bytes, 'skipped' => $skipped, 'done' => $done, 'error' => $error);
    }

    protected function ajaxImgRegistros()
    {
        $idBackup = (int) Tools::getValue('copia');
        if (!$idBackup) {
            return array('ok' => false, 'error' => 'No hay copia previa abierta: no se borra nada.');
        }
        $budget = $this->ajaxBudget();
        $start = microtime(true);
        $deleted = 0;
        $images = 0;
        $done = false;
        do {
            $result = JosralimpiezaImages::cleanMissingBatch($idBackup, 500);
            if (!is_array($result)) {
                return array('ok' => false, 'error' => (string) $result, 'deleted' => $deleted);
            }
            $deleted += (int) $result['deleted'];
            $images += isset($result['images']) ? (int) $result['images'] : 0;
            $done = !empty($result['done']);
        } while (!$done && (microtime(true) - $start) < $budget);

        if ($deleted) {
            JosralimpiezaCore::addHistory('Imágenes sin archivo borradas de la base de datos', $images . ' imágenes', $deleted, 0, $idBackup);
        }

        return array('ok' => true, 'deleted' => $deleted, 'images' => $images, 'done' => $done);
    }

    protected function ajaxTablas()
    {
        return array('ok' => true, 'html' => $this->renderTables(JosralimpiezaCore::tableStatus(), 0, true));
    }

    /* ------------------------------------------------------------------ */
    /* Trozos de interfaz que se repintan por AJAX                         */
    /* ------------------------------------------------------------------ */

    protected function renderTables($tables, $limit = 0, $selectable = false)
    {
        if (!$tables) {
            return '<p class="alert alert-warning">' . $this->l('No se pudo leer el estado de las tablas en este servidor.') . '</p>';
        }
        $html = '<div class="table-responsive"><table class="table"><thead><tr>';
        if ($selectable) {
            $html .= '<th style="width:30px"><input type="checkbox" id="jl-tablas-todas"></th>';
        }
        $html .= '<th>' . $this->l('Tabla') . '</th><th>' . $this->l('Motor') . '</th>'
            . '<th class="text-right">' . $this->l('Filas (aprox.)') . '</th>'
            . '<th class="text-right">' . $this->l('Peso') . '</th>'
            . '<th class="text-right">' . $this->l('Desperdiciado') . '</th></tr></thead><tbody>';
        $shown = 0;
        foreach ($tables as $table) {
            if ($limit && $shown >= $limit) {
                break;
            }
            ++$shown;
            $html .= '<tr>';
            if ($selectable) {
                $html .= '<td><input type="checkbox" class="jl-tabla" value="' . Tools::safeOutput($table['name']) . '"'
                    . ($table['free'] > 1048576 ? ' checked' : '') . '></td>';
            }
            $html .= '<td>' . Tools::safeOutput($table['name']) . '</td>'
                . '<td>' . Tools::safeOutput($table['engine']) . '</td>'
                . '<td class="text-right">' . (int) $table['rows'] . '</td>'
                . '<td class="text-right">' . JosralimpiezaCore::humanBytes($table['size']) . '</td>'
                . '<td class="text-right">' . ($table['free'] > 0 ? '<strong>' . JosralimpiezaCore::humanBytes($table['free']) . '</strong>' : '—') . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table></div>';
        $html .= '<p class="help-block">'
            . $this->l('Las filas son la estimación que da MySQL para las tablas InnoDB, no un recuento exacto: para saber de verdad cuántas filas se pueden borrar, usa «Contar lo que hay» en la pestaña Base de datos. ')
            . $this->l('Y el peso no baja al borrar: InnoDB se queda el espacio reservado hasta que se optimiza la tabla, así que después de una limpieza grande el peso solo cambia al pasar por la pestaña Optimizar.')
            . '</p>';
        if ($limit && count($tables) > $limit) {
            $html .= '<p class="help-block">' . sprintf($this->l('Las %d tablas más grandes de %d. El resto está en la pestaña Optimizar.'), $limit, count($tables)) . '</p>';
        }

        return $html;
    }

    protected function renderImageSummary($summary, $disk)
    {
        $html = '<p>' . sprintf(
            $this->l('Imágenes de producto: %1$s en %2$d archivos. Cuarentena: %3$s.'),
            '<strong>' . $disk['productos_human'] . '</strong>',
            (int) $disk['productos']['files'],
            $disk['cuarentena_human']
        ) . '</p>';

        if (!$summary['kinds']) {
            return $html . '<p class="alert alert-info">' . $this->l('Todavía no se ha escaneado nada, o no se encontró ninguna imagen huérfana.') . '</p>';
        }

        if ($summary['total_files'] > 0) {
            $html .= '<div class="alert alert-info">'
                . sprintf(
                    $this->l('El escáner solo mira y apunta: %1$s en %2$d archivos siguen en su sitio y la tienda pesa lo mismo. No se mueve nada hasta que pulses «Retirar a la cuarentena lo marcado».'),
                    '<strong>' . $summary['total_human'] . '</strong>',
                    (int) $summary['total_files']
                )
                . '</div>';
        }

        $html .= '<div class="table-responsive"><table class="table"><thead><tr>'
            . '<th style="width:30px"></th><th>' . $this->l('Caso') . '</th>'
            . '<th class="text-right">' . $this->l('Archivos') . '</th>'
            . '<th class="text-right">' . $this->l('Peso') . '</th>'
            . '<th style="width:120px"></th></tr></thead><tbody>';

        $fileKinds = JosralimpiezaImages::fileKinds();
        $dbKinds = JosralimpiezaImages::dbKinds();
        foreach ($summary['kinds'] as $kind => $data) {
            $isFile = array_key_exists($kind, $fileKinds);
            $isDb = array_key_exists($kind, $dbKinds);
            $html .= '<tr><td>';
            if ($isFile) {
                $html .= '<input type="checkbox" class="jl-caso" value="' . Tools::safeOutput($kind) . '" checked>';
            } elseif ($isDb) {
                $html .= '<input type="checkbox" class="jl-caso-bd" value="' . Tools::safeOutput($kind) . '">';
            }
            $html .= '</td><td>' . Tools::safeOutput($data['name']);
            if (!$isFile && !$isDb) {
                $html .= ' <span class="label label-default">' . $this->l('solo informativo, no se toca') . '</span>';
            }
            $html .= '</td><td class="text-right">' . (int) $data['count'] . '</td>'
                . '<td class="text-right">' . Tools::safeOutput($data['human']) . '</td>'
                . '<td><button type="button" class="btn btn-default btn-xs jl-ver-caso" data-caso="' . Tools::safeOutput($kind) . '">'
                . '<i class="icon-eye"></i> ' . $this->l('Ver') . '</button></td></tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    protected function renderBackups()
    {
        $backups = JosralimpiezaBackup::listAll();
        if (!$backups) {
            return '<p class="alert alert-info">' . $this->l('Todavía no hay ninguna copia.') . '</p>';
        }
        $html = '<div class="table-responsive"><table class="table"><thead><tr>'
            . '<th>' . $this->l('Fecha') . '</th><th>' . $this->l('Qué es') . '</th>'
            . '<th>' . $this->l('Tipo') . '</th>'
            . '<th class="text-right">' . $this->l('Elementos') . '</th>'
            . '<th class="text-right">' . $this->l('Peso') . '</th>'
            . '<th>' . $this->l('Acciones') . '</th></tr></thead><tbody>';
        foreach ($backups as $backup) {
            $html .= '<tr><td>' . Tools::safeOutput($backup['fecha']) . '</td>'
                . '<td>' . Tools::safeOutput($backup['label'])
                . ((int) $backup['restored'] ? ' <span class="label label-success">' . $this->l('restaurada') . '</span>' : '')
                . (!$backup['exists'] ? ' <span class="label label-danger">' . $this->l('el archivo ya no está') . '</span>' : '')
                . '</td>'
                . '<td>' . ($backup['kind'] === 'imagenes' ? $this->l('Imágenes en cuarentena') : $this->l('Filas de la base de datos')) . '</td>'
                . '<td class="text-right">' . (int) $backup['items'] . '</td>'
                . '<td class="text-right">' . Tools::safeOutput($backup['human']) . '</td>'
                . '<td>';
            if ($backup['exists']) {
                $html .= '<button type="button" class="btn btn-default btn-xs jl-restaurar" data-copia="' . (int) $backup['id_backup'] . '">'
                    . '<i class="icon-undo"></i> ' . $this->l('Restaurar') . '</button> ';
            }
            $html .= '<button type="button" class="btn btn-default btn-xs jl-borrar-copia" data-copia="' . (int) $backup['id_backup'] . '">'
                . '<i class="icon-trash"></i> ' . $this->l('Borrar') . '</button></td></tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    protected function renderHistory()
    {
        $history = JosralimpiezaCore::history();
        if (!$history) {
            return '<p class="alert alert-info">' . $this->l('Todavía no se ha hecho nada.') . '</p>';
        }
        $html = '<div class="table-responsive"><table class="table"><thead><tr>'
            . '<th>' . $this->l('Fecha') . '</th><th>' . $this->l('Acción') . '</th>'
            . '<th>' . $this->l('Detalle') . '</th>'
            . '<th class="text-right">' . $this->l('Elementos') . '</th>'
            . '<th>' . $this->l('Origen') . '</th></tr></thead><tbody>';
        foreach ($history as $row) {
            $html .= '<tr><td>' . date('d/m/Y H:i', strtotime($row['date_add'])) . '</td>'
                . '<td>' . Tools::safeOutput($row['action']) . '</td>'
                . '<td>' . Tools::safeOutput($row['detail']) . '</td>'
                . '<td class="text-right">' . (int) $row['items'] . '</td>'
                . '<td>' . ($row['source'] === 'automatico' ? $this->l('Automático') : $this->l('A mano')) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }
    /* ------------------------------------------------------------------ */
    /* Limpieza automática                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * ¿Le toca hoy? Se ancla a la hora configurada, así no se va desviando, y si
     * una ejecución se queda a medias sigue tocándole hasta terminar.
     */
    public function cronIsDue()
    {
        $settings = JosralimpiezaCore::settings();
        if ($settings['CRON_FRECUENCIA'] === 'off') {
            return false;
        }
        $today = strtotime(date('Y-m-d') . ' ' . sprintf('%02d:00:00', (int) $settings['CRON_HORA']));
        if (time() < $today) {
            return false;
        }
        $last = (int) Configuration::getGlobalValue(JosralimpiezaCore::key('CRON_ULTIMO'));
        if ($last >= $today) {
            return false;
        }
        if ($settings['CRON_FRECUENCIA'] === 'semanal') {
            return (int) date('N') === 1;
        }
        if ($settings['CRON_FRECUENCIA'] === 'mensual') {
            return (int) date('j') === 1;
        }

        return true;
    }

    /**
     * Segundos que se puede estar trabajando sin pasarse del límite del servidor.
     */
    protected function timeBudget()
    {
        $limit = (int) ini_get('max_execution_time');

        return $limit <= 0 ? 300 : max(15, $limit - 10);
    }

    /**
     * Limpieza automática. Solo toca apartados de riesgo cero de los grupos
     * activados, con copia previa, y nunca imágenes ni OPTIMIZE.
     *
     * @return array lo que ha hecho
     */
    public function runCron()
    {
        @set_time_limit(0);
        $result = array(
            'ejecutada' => false,
            'motivo' => '',
            'apartados' => array(),
            'filas' => 0,
            'parcial' => false,
            'errores' => array(),
            'purga' => array('count' => 0, 'bytes' => 0),
            'copia' => 0,
        );

        $storage = JosralimpiezaCore::ensureStorage();
        if ($storage !== true) {
            $result['motivo'] = $storage;

            return $result;
        }

        // Un solo proceso a la vez
        $lockFile = JosralimpiezaCore::storageDir() . 'cron.lock';
        $lock = @fopen($lockFile, 'c');
        if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            $result['motivo'] = 'Ya hay una limpieza automática en marcha: esta llamada no hace nada.';

            return $result;
        }

        $result['purga'] = JosralimpiezaBackup::purge();

        if (!$this->cronIsDue()) {
            $result['motivo'] = 'Todavía no le toca según la frecuencia y la hora configuradas.';
            @flock($lock, LOCK_UN);
            fclose($lock);

            return $result;
        }

        $start = time();
        $budget = $this->timeBudget();
        $batch = (int) JosralimpiezaCore::setting('LOTE_BD');
        $items = JosralimpiezaCore::cronItems();
        $idBackup = 0;
        $finished = true;

        foreach ($items as $item) {
            if (time() - $start >= $budget) {
                $finished = false;
                $result['parcial'] = true;
                break;
            }
            if ($item['backup'] && !$idBackup) {
                $open = JosralimpiezaBackup::start('bd', 'Limpieza automática del ' . date('d/m/Y H:i'));
                if (!is_int($open)) {
                    $result['errores'][] = $open;
                    $finished = false;
                    break;
                }
                $idBackup = $open;
                $result['copia'] = $idBackup;
            }
            $deleted = 0;
            $done = false;
            while (!$done) {
                $batchResult = JosralimpiezaDb::cleanItem($item, $batch, $item['backup'] ? $idBackup : 0);
                if (!is_array($batchResult)) {
                    $result['errores'][] = $batchResult;
                    $finished = false;
                    break;
                }
                $deleted += (int) $batchResult['deleted'];
                $done = !empty($batchResult['done']);
                if (!empty($batchResult['truncated'])) {
                    $deleted = -1;
                    break;
                }
                if (!$done && time() - $start >= $budget) {
                    $finished = false;
                    $result['parcial'] = true;
                    break;
                }
            }
            if ($deleted === -1) {
                $result['apartados'][$item['group'] . ' › ' . $item['name']] = 'vaciada';
            } elseif ($deleted > 0) {
                $result['apartados'][$item['group'] . ' › ' . $item['name']] = $deleted;
                $result['filas'] += $deleted;
            }
        }

        if ($idBackup) {
            JosralimpiezaBackup::finish($idBackup);
        }
        if ($finished) {
            Configuration::updateGlobalValue(JosralimpiezaCore::key('CRON_ULTIMO'), time());
        }

        $result['ejecutada'] = true;
        $summary = $this->cronSummaryText($result);
        Configuration::updateGlobalValue(JosralimpiezaCore::key('CRON_RESUMEN'), $summary);
        JosralimpiezaCore::addHistory(
            'Limpieza automática',
            $summary,
            (int) $result['filas'],
            0,
            (int) $result['copia'],
            'automatico'
        );

        if ((int) JosralimpiezaCore::setting('CORREO_ACTIVO')) {
            $this->sendReport($result);
        }

        @flock($lock, LOCK_UN);
        fclose($lock);

        return $result;
    }

    public function cronSummaryText($result)
    {
        $parts = array();
        if ($result['filas']) {
            $parts[] = $result['filas'] . ' filas borradas';
        }
        $vaciadas = 0;
        foreach ($result['apartados'] as $value) {
            if ($value === 'vaciada') {
                ++$vaciadas;
            }
        }
        if ($vaciadas) {
            $parts[] = $vaciadas . ' tablas de caché vaciadas';
        }
        if (!$parts) {
            $parts[] = 'no había nada que limpiar';
        }
        if ($result['purga']['count']) {
            $parts[] = $result['purga']['count'] . ' copias antiguas borradas';
        }
        if ($result['parcial']) {
            $parts[] = 'quedó trabajo a medias por el tiempo del servidor: la siguiente llamada sigue donde lo dejó';
        }
        if ($result['errores']) {
            $parts[] = count($result['errores']) . ' errores (' . $result['errores'][0] . ')';
        }

        return ucfirst(implode('; ', $parts)) . '.';
    }

    /**
     * Informe por correo de lo que ha hecho el automático y de cómo está la tienda.
     */
    protected function sendReport($result)
    {
        $to = (string) JosralimpiezaCore::setting('CORREO');
        if (!Validate::isEmail($to)) {
            return false;
        }

        $tables = JosralimpiezaCore::tableStatus();
        $size = 0;
        $free = 0;
        foreach ($tables as $table) {
            $size += $table['size'];
            $free += $table['free'];
        }
        $disk = JosralimpiezaImages::diskUsage();

        $lines = '';
        $plain = '';
        foreach ($result['apartados'] as $name => $value) {
            $what = $value === 'vaciada' ? 'tabla vaciada' : $value . ' filas';
            $lines .= '<li>' . Tools::safeOutput($name) . ': <strong>' . Tools::safeOutput($what) . '</strong></li>';
            $plain .= '- ' . $name . ': ' . $what . "\n";
        }
        if ($lines === '') {
            $lines = '<li>No había nada que limpiar.</li>';
            $plain = "- No había nada que limpiar.\n";
        }
        $errors = '';
        foreach ($result['errores'] as $error) {
            $errors .= '<li>' . Tools::safeOutput($error) . '</li>';
            $plain .= '! ' . $error . "\n";
        }
        if ($errors !== '') {
            $errors = '<p><strong>Errores:</strong></p><ul>' . $errors . '</ul>';
        }
        if ($result['parcial']) {
            $errors .= '<p>Quedó trabajo a medias por el límite de tiempo del servidor. La siguiente llamada de cron sigue donde lo dejó.</p>';
        }

        $vars = array(
            '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
            '{fecha}' => date('d/m/Y H:i'),
            '{resumen}' => $this->cronSummaryText($result),
            '{apartados}' => $lines,
            '{errores}' => $errors,
            '{peso_bd}' => JosralimpiezaCore::humanBytes($size),
            '{desperdiciado}' => JosralimpiezaCore::humanBytes($free),
            '{peso_imagenes}' => $disk['productos_human'],
            '{peso_cuarentena}' => $disk['cuarentena_human'],
            '{copias}' => (string) $result['purga']['count'],
            '{url}' => $this->configUrl(),
            '{texto}' => $plain,
        );

        return Mail::Send(
            $this->reportLangId(),
            'josralimpieza_informe',
            'Limpieza de la tienda ' . Configuration::get('PS_SHOP_NAME') . ' — ' . date('d/m/Y'),
            $vars,
            $to,
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . $this->name . '/mails/',
            false,
            (int) Configuration::get('PS_SHOP_DEFAULT')
        );
    }

    /**
     * Idioma del informe: el de la tienda si hay plantilla para ese idioma, y si
     * no, español o inglés, que van dentro del módulo.
     */
    protected function reportLangId()
    {
        $path = _PS_MODULE_DIR_ . $this->name . '/mails/';
        $default = (int) Configuration::get('PS_LANG_DEFAULT');
        $languages = Language::getLanguages(false);
        $byIso = array();
        foreach ($languages as $language) {
            $byIso[Tools::strtolower($language['iso_code'])] = (int) $language['id_lang'];
            if ((int) $language['id_lang'] === $default && is_dir($path . $language['iso_code'])) {
                return $default;
            }
        }
        foreach (array('es', 'en') as $iso) {
            if (isset($byIso[$iso]) && is_dir($path . $iso)) {
                return $byIso[$iso];
            }
        }
        foreach ($languages as $language) {
            if (is_dir($path . $language['iso_code'])) {
                return (int) $language['id_lang'];
            }
        }

        return $default;
    }

}
