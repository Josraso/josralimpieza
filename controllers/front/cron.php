<?php
/**
 * URL para la limpieza automática.
 *
 * Se llama desde una tarea programada del servidor (una vez por hora está bien):
 * el módulo decide solo si le toca según la frecuencia y la hora configuradas.
 * La clave de la URL la genera la propia tienda.
 *
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosralimpiezaCronModuleFrontController extends ModuleFrontController
{
    /** @var bool no necesita las plantillas del tema */
    public $ssl = true;

    public function initContent()
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/plain; charset=utf-8');

        if (!Module::isInstalled('josralimpieza') || !Module::isEnabled('josralimpieza')) {
            header('HTTP/1.1 503 Service Unavailable');
            die("josralimpieza: el modulo no esta instalado o esta desactivado en esta tienda.\n");
        }

        $expected = $this->module->cronToken();
        $given = (string) Tools::getValue('token');
        $valid = function_exists('hash_equals') ? hash_equals($expected, $given) : ($expected === $given);
        if (!$valid) {
            header('HTTP/1.1 403 Forbidden');
            die("josralimpieza: la clave de la URL no es correcta. Copiala otra vez desde Modulos > Limpieza de base de datos e imagenes > Configurar > Ajustes y automatico.\n");
        }

        $result = $this->module->runCron();

        echo 'josralimpieza ' . $this->module->version . ' — ' . date('d/m/Y H:i:s') . "\n";
        if (!$result['ejecutada']) {
            echo 'Sin limpieza: ' . $result['motivo'] . "\n";
        } else {
            echo $this->module->cronSummaryText($result) . "\n";
            foreach ($result['apartados'] as $name => $value) {
                echo '  - ' . $name . ': ' . ($value === 'vaciada' ? 'tabla vaciada' : $value . ' filas') . "\n";
            }
            foreach ($result['errores'] as $error) {
                echo '  ! ' . $error . "\n";
            }
        }
        if ($result['purga']['count']) {
            echo 'Copias antiguas borradas: ' . (int) $result['purga']['count'] . "\n";
        }
        die();
    }
}
