<?php
/**
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    // Lotes más grandes por defecto en las tiendas que venían de la 1.0.0 con el valor viejo
    if ((int) Configuration::getGlobalValue('JOSRALIMPIEZA_LOTE_BD') <= 500) {
        Configuration::updateGlobalValue('JOSRALIMPIEZA_LOTE_BD', 2000);
    }

    return $module->getUpdater()->registerHooks();
}
