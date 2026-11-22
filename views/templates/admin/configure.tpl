{*
 * Limpieza de base de datos e imágenes — configuración del módulo.
 * @author josra
 *}
<link rel="stylesheet" type="text/css" href="{$jl_module_dir|escape:'html':'UTF-8'}views/css/admin.css?v={$jl_version|escape:'html':'UTF-8'}">

<div id="josralimpieza">

    <div class="pull-right" style="margin:4px 0 0 10px"><span class="label label-default">v{$jl_version|escape:'html':'UTF-8'}</span></div>

    <ul class="nav nav-tabs" style="margin-bottom:15px">
        <li class="active"><a href="#" class="jl-tab" data-pane="diagnostico"><i class="icon-dashboard"></i> Diagnóstico</a></li>
        <li><a href="#" class="jl-tab" data-pane="bd"><i class="icon-database"></i> Base de datos</a></li>
        <li><a href="#" class="jl-tab" data-pane="img"><i class="icon-picture"></i> Imágenes</a></li>
        <li><a href="#" class="jl-tab" data-pane="opt"><i class="icon-tasks"></i> Optimizar</a></li>
        <li><a href="#" class="jl-tab" data-pane="copias"><i class="icon-hdd"></i> Copias e historial</a></li>
        <li><a href="#" class="jl-tab" data-pane="ajustes"><i class="icon-cogs"></i> Ajustes y automático</a></li>
    </ul>

    <div class="tab-content">

        {* ---------------------------------------------------------------- *}
        {* Diagnóstico                                                      *}
        {* ---------------------------------------------------------------- *}
        <div class="jl-pane active" id="jl-diagnostico">
            <div class="panel">
                <div class="panel-heading"><i class="icon-dashboard"></i> Cómo está la tienda</div>
                <p>
                    Mira cuánto pesa la base de datos, cuánto espacio hay desperdiciado y cuánto ocupan las imágenes de producto.
                    Aquí no se toca nada: solo se lee.
                </p>
                {if $jl_multistore}
                    <div class="alert alert-info">
                        Esta instalación tiene multitienda activado. La limpieza y las imágenes son de toda la instalación, no de una tienda sola.
                    </div>
                {/if}
                <p>
                    <button type="button" class="btn btn-primary" id="jl-analizar"><i class="icon-refresh"></i> Analizar la tienda</button>
                </p>
                <div id="jl-diag-estado"></div>
                <div class="row jl-kpi" id="jl-diag-kpi" style="display:none">
                    <div class="col-lg-2 col-md-4"><span class="jl-kpi-valor" id="jl-kpi-bd">—</span><span class="jl-kpi-nombre">Peso de la base de datos</span></div>
                    <div class="col-lg-2 col-md-4"><span class="jl-kpi-valor" id="jl-kpi-free">—</span><span class="jl-kpi-nombre">Espacio desperdiciado</span></div>
                    <div class="col-lg-2 col-md-4"><span class="jl-kpi-valor" id="jl-kpi-tablas">—</span><span class="jl-kpi-nombre">Tablas</span></div>
                    <div class="col-lg-2 col-md-4"><span class="jl-kpi-valor" id="jl-kpi-img">—</span><span class="jl-kpi-nombre">Imágenes de producto</span></div>
                    <div class="col-lg-2 col-md-4"><span class="jl-kpi-valor" id="jl-kpi-cuarentena">—</span><span class="jl-kpi-nombre">En cuarentena</span></div>
                    <div class="col-lg-2 col-md-4"><span class="jl-kpi-valor" id="jl-kpi-copias">—</span><span class="jl-kpi-nombre">Copias guardadas</span></div>
                </div>
                <div id="jl-diag-tablas"></div>
            </div>
        </div>

        {* ---------------------------------------------------------------- *}
        {* Base de datos                                                    *}
        {* ---------------------------------------------------------------- *}
        <div class="jl-pane" id="jl-bd">
            <div class="panel">
                <div class="panel-heading"><i class="icon-database"></i> Limpieza de la base de datos</div>
                <p>
                    Primero <strong>Contar lo que hay</strong>: así ves cuántas filas se borrarían en cada apartado sin tocar nada.
                    Con <strong>Ver</strong> se enseñan las primeras filas de verdad. Al limpiar, cada lote se guarda antes en una
                    copia (pestaña <em>Copias</em>) desde la que se puede devolver todo.
                </p>
                <div class="alert alert-warning">
                    <label style="font-weight:bold">
                        <input type="checkbox" id="jl-confirmo"> Tengo una copia de seguridad completa del servidor hecha hoy
                    </label>
                    <div>Las copias del módulo cubren lo que borra el módulo, pero antes de una limpieza grande conviene tener la copia completa del servidor.</div>
                </div>

                <div class="well" style="padding:10px 15px">
                    <strong>Marcar:</strong>
                    <button type="button" class="btn btn-default btn-xs" id="jl-marcar-todo">Todo</button>
                    <button type="button" class="btn btn-default btn-xs" id="jl-marcar-seguras">Solo lo seguro</button>
                    <button type="button" class="btn btn-default btn-xs" id="jl-marcar-nada">Nada</button>
                    <span style="display:inline-block;width:25px"></span>
                    <label style="font-weight:bold">
                        <input type="checkbox" id="jl-con-copia" checked> Guardar copia previa de lo que se borre
                    </label>
                    <div style="margin-top:10px">
                        <strong>Antigüedad para esta limpieza:</strong>
                        <select id="jl-meses" class="fixed-width-lg" style="display:inline-block;width:auto">
                            <option value="">La de Ajustes ({$jl_settings.ANTIGUEDAD_REGISTROS|intval} meses en registros, {$jl_settings.ANTIGUEDAD_CARRITOS|intval} en carritos)</option>
                            <option value="0">Todo, sin mirar la fecha</option>
                            <option value="1">Más de 1 mes</option>
                            <option value="3">Más de 3 meses</option>
                            <option value="6">Más de 6 meses</option>
                            <option value="12">Más de 12 meses</option>
                            <option value="24">Más de 24 meses</option>
                        </select>
                        <span id="jl-meses-fecha" class="help-block" style="display:inline-block;margin:0 0 0 10px"></span>
                        <div class="help-block" style="margin:6px 0 0">
                            Solo para el recuento y la limpieza de ahora, no cambia los ajustes. Si una tabla enorme sale a 0 filas es
                            porque todo lo que tiene es más nuevo que esta fecha: con tráfico de bots, millones de visitas se juntan en
                            pocas semanas.
                        </div>
                    </div>
                    <div class="help-block" style="margin:6px 0 0">
                        La copia deja restaurar lo borrado, pero en tablas de millones de filas es lo que más tarda, porque hay que
                        leer y escribir cada fila. Sin copia, el borrado va directo y es muchísimo más rápido, pero
                        <strong>no se puede deshacer</strong>. Para registros y estadísticas no suele hacer falta copia.
                    </div>
                </div>

                {foreach from=$jl_groups key=idgroup item=group}
                    <div class="panel panel-default">
                        <div class="panel-heading">{$group.name|escape:'html':'UTF-8'}</div>
                        <p class="help-block" style="margin:0 0 10px">{$group.help|escape:'html':'UTF-8'}</p>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width:30px">
                                            <input type="checkbox" class="jl-grupo" title="Marcar o desmarcar todo el grupo">
                                        </th>
                                        <th>Qué se limpia</th>
                                        <th>Tabla</th>
                                        <th class="text-right" style="width:120px">Filas</th>
                                        <th style="width:90px"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {foreach from=$group.items key=iditem item=item}
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="jl-item" value="{$iditem|escape:'html':'UTF-8'}"
                                                    data-nombre="{$item.name|escape:'html':'UTF-8'}" data-riesgo="{$item.risk|intval}"
                                                    {if $item.risk == 0}checked{/if}{if isset($item.unavailable)} disabled{/if}>
                                            </td>
                                            <td>
                                                {$item.name|escape:'html':'UTF-8'}
                                                {if $item.risk == 1}<span class="label label-warning jl-riesgo">revisar antes</span>{/if}
                                                {if $item.risk == 2}<span class="label label-danger jl-riesgo">hay que reconstruirlo después</span>{/if}
                                                {if $item.truncate}<span class="label label-default jl-riesgo">se vacía entera</span>{/if}
                                                {if isset($item.unavailable)}<br><span class="text-muted">{$item.unavailable|escape:'html':'UTF-8'}</span>{/if}
                                            </td>
                                            <td><code>{$item.table|escape:'html':'UTF-8'}</code></td>
                                            <td class="text-right jl-count" data-item="{$iditem|escape:'html':'UTF-8'}">—</td>
                                            <td>
                                                {if !isset($item.unavailable)}
                                                    <button type="button" class="btn btn-default btn-xs jl-ver" data-item="{$iditem|escape:'html':'UTF-8'}">
                                                        <i class="icon-eye"></i> Ver
                                                    </button>
                                                {/if}
                                            </td>
                                        </tr>
                                    {/foreach}
                                </tbody>
                            </table>
                        </div>
                    </div>
                {/foreach}

                <div class="panel-footer">
                    <button type="button" class="btn btn-default jl-bloquea-bd" id="jl-contar"><i class="icon-calculator"></i> Contar lo que hay</button>
                    <button type="button" class="btn btn-danger jl-bloquea-bd" id="jl-limpiar"><i class="icon-eraser"></i> Limpiar lo marcado</button>
                    <button type="button" class="btn btn-warning jl-pausa jl-pausa-bd" data-scope="bd" style="display:none"><i class="icon-pause"></i> Pausa</button>
                </div>

                <div class="jl-progreso" id="jl-bd-progreso">
                    <div class="progress"><div class="progress-bar" style="width:0">0%</div></div>
                    <div class="jl-progreso-texto"></div>
                </div>
                <div id="jl-bd-banner" style="display:none"></div>
                <div class="jl-visor" id="jl-visor"></div>
                <div class="jl-log" id="jl-bd-log"></div>
            </div>
        </div>

        {* ---------------------------------------------------------------- *}
        {* Imágenes                                                         *}
        {* ---------------------------------------------------------------- *}
        <div class="jl-pane" id="jl-img">
            <div class="panel">
                <div class="panel-heading"><i class="icon-picture"></i> Imágenes de producto huérfanas</div>
                <p>
                    El escáner recorre <code>img/p</code> por carpetas y busca solo imágenes que la tienda ya no usa:
                </p>
                <ul>
                    <li><strong>Archivos sin ninguna imagen en la base de datos</strong>: quedaron de borrados a medias.</li>
                    <li><strong>Imágenes de productos que ya no existen</strong>: el producto se borró y su imagen se quedó.</li>
                    <li><strong>Miniaturas de tamaños de imagen que ya no existen</strong>: de tamaños que se quitaron de la tienda.</li>
                    <li><strong>Imágenes de la base de datos cuyo archivo no está</strong>: aquí no hay archivo que mover, se borra la fila.</li>
                </ul>
                <p>
                    Los archivos <strong>no se borran</strong>: se mueven a la cuarentena del módulo, de donde se devuelven con un clic.
                    Lo que no encaja en ningún caso (nombres raros y las imágenes planas de tiendas anteriores a la 1.5, que están en la raíz
                    de <code>img/p</code>) solo se cuenta, no se toca nunca.
                </p>
                <div class="alert alert-warning">
                    <label style="font-weight:bold">
                        <input type="checkbox" id="jl-img-confirmo"> Tengo una copia de seguridad completa del servidor hecha hoy
                    </label>
                </div>
                <p>
                    <button type="button" class="btn btn-primary jl-bloquea-img jl-escanear" id="jl-img-escanear" data-reiniciar="1"><i class="icon-search"></i> Escanear de cero</button>
                    <button type="button" class="btn btn-default jl-bloquea-img jl-escanear" id="jl-img-seguir"><i class="icon-play"></i> Seguir el escáner</button>
                    <button type="button" class="btn btn-warning jl-pausa jl-pausa-img" data-scope="img" style="display:none"><i class="icon-pause"></i> Pausa</button>
                </p>
                <div id="jl-img-estado" class="jl-progreso-texto"></div>
                <div id="jl-img-banner" style="display:none"></div>
                <div id="jl-img-resumen">{$jl_images_html nofilter}</div>
                <div class="panel-footer">
                    <button type="button" class="btn btn-danger jl-bloquea-img" id="jl-img-retirar"><i class="icon-archive"></i> Retirar a la cuarentena lo marcado</button>
                    <button type="button" class="btn btn-default jl-bloquea-img" id="jl-img-registros"><i class="icon-eraser"></i> Borrar los registros sin archivo</button>
                </div>
                <div class="jl-visor" id="jl-img-visor"></div>
                <div class="jl-log" id="jl-img-log"></div>
            </div>
        </div>

        {* ---------------------------------------------------------------- *}
        {* Optimizar                                                        *}
        {* ---------------------------------------------------------------- *}
        <div class="jl-pane" id="jl-opt">
            <div class="panel">
                <div class="panel-heading"><i class="icon-tasks"></i> Optimizar las tablas</div>
                <p>
                    Después de borrar muchas filas, el espacio sigue reservado en el disco hasta que se optimiza la tabla.
                    Vienen marcadas las que desperdician más de 1 MB.
                </p>
                <div class="alert alert-warning">
                    Mientras se optimiza una tabla, la tienda <strong>no puede escribir en ella</strong>, y con InnoDB el servidor necesita
                    en disco libre al menos lo que pese la tabla más grande que se optimice. Hazlo con la tienda tranquila y nunca lo deja
                    hacer el automático.
                </div>
                <p>
                    <button type="button" class="btn btn-default jl-bloquea-opt" id="jl-tablas-cargar"><i class="icon-refresh"></i> Ver el estado de las tablas</button>
                    <button type="button" class="btn btn-danger jl-bloquea-opt" id="jl-optimizar"><i class="icon-tasks"></i> Optimizar las marcadas</button>
                    <button type="button" class="btn btn-warning jl-pausa jl-pausa-opt" data-scope="opt" style="display:none"><i class="icon-pause"></i> Pausa</button>
                </p>
                <div class="jl-progreso" id="jl-opt-progreso">
                    <div class="progress"><div class="progress-bar" style="width:0">0%</div></div>
                    <div class="jl-progreso-texto"></div>
                </div>
                <div id="jl-opt-banner" style="display:none"></div>
                <div id="jl-tablas"></div>
                <div class="jl-log" id="jl-opt-log"></div>
            </div>
        </div>

        {* ---------------------------------------------------------------- *}
        {* Copias e historial                                               *}
        {* ---------------------------------------------------------------- *}
        <div class="jl-pane" id="jl-copias">
            <div class="panel">
                <div class="panel-heading"><i class="icon-hdd"></i> Copias previas</div>
                <p>
                    Cada limpieza guarda aquí lo que ha borrado: las filas como un <code>.sql.gz</code> y las imágenes en una carpeta de
                    cuarentena. <strong>Restaurar</strong> lo devuelve todo a su sitio. Pasados
                    <strong>{$jl_settings.RETENCION|intval}</strong> días (se cambia en Ajustes), las copias se borran solas.
                </p>
                <p>
                    <button type="button" class="btn btn-default" id="jl-copias-recargar"><i class="icon-refresh"></i> Actualizar la lista</button>
                </p>
                <div id="jl-copias-estado"></div>
                <div id="jl-copias-lista">{$jl_backups_html nofilter}</div>
            </div>
            <div class="panel">
                <div class="panel-heading"><i class="icon-list"></i> Historial</div>
                <p>
                    <button type="button" class="btn btn-default" id="jl-historial-recargar"><i class="icon-refresh"></i> Actualizar</button>
                </p>
                <div id="jl-historial">{$jl_history_html nofilter}</div>
            </div>
        </div>

        {* ---------------------------------------------------------------- *}
        {* Ajustes y automático                                             *}
        {* ---------------------------------------------------------------- *}
        <div class="jl-pane" id="jl-ajustes">
            {$jl_form nofilter}

            <div class="panel">
                <div class="panel-heading"><i class="icon-time"></i> URL para el automático</div>
                <p>
                    El automático solo funciona si algo llama a esta dirección. Pon una tarea programada en el servidor (en Plesk,
                    <em>Tareas programadas</em>) que la llame <strong>cada hora</strong>: el módulo decide solo si le toca según la
                    frecuencia y la hora de arriba, y si una vez se queda a medias por el tiempo del servidor, la llamada siguiente
                    sigue donde lo dejó.
                </p>
                <div class="form-group">
                    <label>Dirección</label>
                    <input type="text" class="form-control" readonly value="{$jl_cron_url|escape:'html':'UTF-8'}" onclick="this.select();">
                </div>
                <div class="form-group">
                    <label>Con wget</label>
                    <input type="text" class="form-control" readonly value="wget -q -O /dev/null &quot;{$jl_cron_url|escape:'html':'UTF-8'}&quot;" onclick="this.select();">
                </div>
                <div class="form-group">
                    <label>Con curl</label>
                    <input type="text" class="form-control" readonly value="curl -s -o /dev/null &quot;{$jl_cron_url|escape:'html':'UTF-8'}&quot;" onclick="this.select();">
                </div>
                <p class="help-block">
                    La dirección lleva su propia clave, generada por esta tienda. No hace falta tocar nada más.
                </p>
                {if $jl_cron_last}
                    <div class="alert alert-info">
                        Última limpieza automática: <strong>{$jl_cron_last|escape:'html':'UTF-8'}</strong>.
                        {if $jl_cron_summary}<br>{$jl_cron_summary|escape:'html':'UTF-8'}{/if}
                    </div>
                {else}
                    <div class="alert alert-info">Todavía no se ha ejecutado ninguna limpieza automática.</div>
                {/if}
            </div>

            <div class="panel">
                <div class="panel-heading"><i class="icon-info-circle"></i> Qué hace y qué no hace el automático</div>
                <ul>
                    <li>Limpia solo los apartados de riesgo cero de los grupos que estén activados arriba.</li>
                    <li>Nunca toca imágenes, ni carritos, ni clientes, ni el índice del buscador, ni optimiza tablas.</li>
                    <li>Guarda copia previa de todo lo que borra, igual que cuando se hace a mano.</li>
                    <li>Borra las copias que han pasado los días de retención.</li>
                    <li>Manda el informe por correo, si está activado.</li>
                </ul>
                <p class="help-block">Versión del módulo: {$jl_version|escape:'html':'UTF-8'}</p>
            </div>
        </div>

    </div>
</div>

<script type="text/javascript">
    var jlAjax = "{$jl_ajax|escape:'javascript':'UTF-8'}";
</script>
<script type="text/javascript" src="{$jl_module_dir|escape:'html':'UTF-8'}views/js/admin.js?v={$jl_version|escape:'html':'UTF-8'}"></script>
