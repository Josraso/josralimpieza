/**
 * Limpieza de base de datos e imágenes — interfaz del back-office.
 *
 * Todo el trabajo se parte en lotes y se pide por AJAX uno detrás de otro, con
 * pausa, contador en vivo y un registro acotado, para no cargar el servidor.
 *
 * @author josra
 */
(function ($) {
    'use strict';

    if (typeof window.jlAjax === 'undefined') {
        return;
    }

    var url = window.jlAjax;
    var paused = {};
    var running = {};

    /* ------------------------------------------------------------------ */
    /* Utilidades                                                          */
    /* ------------------------------------------------------------------ */

    function post(accion, data, done) {
        data = data || {};
        data.accion = accion;
        $.ajax({ url: url, type: 'POST', data: data, dataType: 'json' })
            .done(function (r) {
                done(r && typeof r === 'object' ? r : { ok: false, error: 'El servidor devolvió una respuesta vacía.' });
            })
            .fail(function (xhr) {
                var motivo = xhr.status
                    ? 'el servidor respondió ' + xhr.status + ' ' + xhr.statusText
                    : 'no se pudo contactar con el servidor';
                done({
                    ok: false,
                    error: 'Falló la llamada al servidor: ' + motivo
                        + '. Si se repite, baja las filas por lote en la pestaña Ajustes.'
                });
            });
    }

    function log(box, text, type) {
        var $box = $(box);
        var clase = type ? ' class="jl-log-' + type + '"' : '';
        $box.show().append('<div' + clase + '>' + hora() + ' ' + escapar(text) + '</div>');
        var $lines = $box.children();
        if ($lines.length > 500) {
            $lines.slice(0, $lines.length - 500).remove();
        }
        $box.scrollTop($box[0].scrollHeight);
    }

    function hora() {
        var d = new Date();
        return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2);
    }

    function escapar(text) {
        return $('<span>').text(text === null || typeof text === 'undefined' ? '' : text).html();
    }

    function progreso(selector, done, total, texto) {
        var percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
        var $box = $(selector).show();
        $box.find('.progress-bar').css('width', percent + '%').text(percent + '%');
        $box.find('.jl-progreso-texto').text(texto || '');
    }

    function banner(selector, type, title, text) {
        $(selector).show().html(
            '<div class="alert alert-' + type + '"><strong>' + escapar(title) + '</strong><br>' + escapar(text) + '</div>'
        );
    }

    function segundos(desde) {
        var s = Math.round((new Date().getTime() - desde) / 1000);
        if (s < 60) {
            return s + ' s';
        }
        return Math.floor(s / 60) + ' min ' + (s % 60) + ' s';
    }

    function bloquear(scope, on) {
        running[scope] = on;
        $('.jl-bloquea-' + scope).prop('disabled', on);
        var $pausa = $('.jl-pausa-' + scope);
        $pausa.toggle(on);
        if (on) {
            $pausa.prop('disabled', false).html('<i class="icon-pause"></i> Pausa');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Pestañas                                                            */
    /* ------------------------------------------------------------------ */

    $(document).on('click', '#josralimpieza .jl-tab', function (e) {
        e.preventDefault();
        var pane = $(this).data('pane');
        $('#josralimpieza .jl-tab').parent().removeClass('active');
        $(this).parent().addClass('active');
        $('#josralimpieza .jl-pane').removeClass('active').hide();
        $('#jl-' + pane).addClass('active').show();
    });

    /* ------------------------------------------------------------------ */
    /* Diagnóstico                                                         */
    /* ------------------------------------------------------------------ */

    $(document).on('click', '#jl-analizar', function () {
        var $boton = $(this).prop('disabled', true);
        $('#jl-diag-estado').html('Leyendo el tamaño de las tablas y de las carpetas de imágenes…');
        post('diagnostico', {}, function (r) {
            $boton.prop('disabled', false);
            if (!r.ok) {
                $('#jl-diag-estado').html('<div class="alert alert-danger">' + escapar(r.error) + '</div>');
                return;
            }
            $('#jl-diag-estado').html('');
            $('#jl-kpi-bd').text(r.peso);
            $('#jl-kpi-free').text(r.desperdiciado);
            $('#jl-kpi-tablas').text(r.tablas);
            $('#jl-kpi-img').text(r.imagenes + ' (' + r.imagenes_archivos + ' archivos)');
            $('#jl-kpi-copias').text(r.copias + ' (' + r.copias_peso + ')');
            $('#jl-kpi-cuarentena').text(r.cuarentena);
            $('#jl-diag-kpi').show();
            $('#jl-diag-tablas').html(r.html);
        });
    });

    /* ------------------------------------------------------------------ */
    /* Base de datos: contar                                               */
    /* ------------------------------------------------------------------ */

    $(document).on('click', '#jl-contar', function () {
        var items = [];
        $('#jl-bd .jl-item:not(:disabled)').each(function () {
            items.push($(this).val());
        });
        if (!items.length) {
            return;
        }
        paused.bd = false;
        bloquear('bd', true);
        $('#jl-bd-banner').hide();
        var total = items.length;
        var index = 0;
        var inicio = new Date().getTime();

        (function siguiente() {
            if (paused.bd || index >= total) {
                bloquear('bd', false);
                progreso('#jl-bd-progreso', index, total, 'Contado en ' + segundos(inicio));
                if (!paused.bd) {
                    banner('#jl-bd-banner', 'info', 'Recuento terminado (' + segundos(inicio) + ')',
                        'Ya sabes cuántas filas se borrarían en cada apartado. Mira con «Ver» lo que no tengas claro antes de limpiar.');
                }
                return;
            }
            var item = items[index++];
            progreso('#jl-bd-progreso', index, total, 'Contando ' + item + '…');
            post('contar', { item: item }, function (r) {
                var $celda = $('#jl-bd .jl-count[data-item="' + item + '"]');
                if (r.ok) {
                    $celda.html(r.filas > 0 ? '<strong>' + r.filas + '</strong>' : '0');
                    $('#jl-bd .jl-item[value="' + item + '"]').data('filas', r.filas);
                } else {
                    $celda.html('<span class="text-danger">error</span>');
                    log('#jl-bd-log', item + ': ' + r.error, 'error');
                }
                siguiente();
            });
        })();
    });

    $(document).on('click', '#jl-marcar-todo', function () {
        $('#jl-bd .jl-item:not(:disabled)').prop('checked', true);
        $('#jl-bd .jl-grupo').prop('checked', true);
    });

    $(document).on('click', '#jl-marcar-nada', function () {
        $('#jl-bd .jl-item').prop('checked', false);
        $('#jl-bd .jl-grupo').prop('checked', false);
    });

    $(document).on('click', '#jl-marcar-seguras', function () {
        $('#jl-bd .jl-item:not(:disabled)').each(function () {
            $(this).prop('checked', parseInt($(this).data('riesgo'), 10) === 0);
        });
        $('#jl-bd .jl-grupo').prop('checked', false);
    });

    $(document).on('change', '#jl-bd .jl-grupo', function () {
        $(this).closest('table').find('.jl-item:not(:disabled)').prop('checked', $(this).is(':checked'));
    });

    $(document).on('click', '.jl-ver', function () {
        var item = $(this).data('item');
        $('#jl-visor').show().html('<p>Leyendo las primeras filas…</p>');
        post('ver', { item: item }, function (r) {
            $('#jl-visor').html(r.ok ? r.html : '<div class="alert alert-danger">' + escapar(r.error) + '</div>');
        });
    });

    /* ------------------------------------------------------------------ */
    /* Base de datos: limpiar                                              */
    /* ------------------------------------------------------------------ */

    $(document).on('click', '#jl-limpiar', function () {
        if (!$('#jl-confirmo').is(':checked')) {
            alert('Marca antes que tienes una copia de seguridad completa del servidor.');
            return;
        }
        var items = [];
        var riesgo = false;
        $('#jl-bd .jl-item:checked:not(:disabled)').each(function () {
            items.push({ id: $(this).val(), nombre: $(this).data('nombre') });
            if (parseInt($(this).data('riesgo'), 10) > 0) {
                riesgo = true;
            }
        });
        if (!items.length) {
            alert('No has marcado ningún apartado.');
            return;
        }
        var conCopia = $('#jl-con-copia').is(':checked');
        var aviso = 'Se van a borrar las filas de ' + items.length + ' apartado(s).\n\n';
        aviso += conCopia
            ? 'Antes de borrar cada lote se guarda una copia en la pestaña Copias, desde la que se pueden devolver.'
            : 'SIN copia previa: va mucho más rápido, pero lo que se borre NO se podrá recuperar desde el módulo.';
        if (riesgo) {
            aviso += '\n\nHay apartados marcados que conviene revisar antes (carritos, clientes, catálogo).';
        }
        if (!confirm(aviso + '\n\n¿Seguir?')) {
            return;
        }

        paused.bd = false;
        bloquear('bd', true);
        $('#jl-bd-banner').hide();
        var inicio = new Date().getTime();
        var borradas = 0;
        var index = 0;
        var errores = 0;
        var totalFilas = 0;
        $('#jl-bd .jl-item:checked:not(:disabled)').each(function () {
            totalFilas += parseInt($(this).data('filas'), 10) || 0;
        });

        function conCopiaAbierta(r) {
            if (!r.ok) {
                bloquear('bd', false);
                banner('#jl-bd-banner', 'danger', 'No se ha borrado nada', r.error);
                return;
            }
            var copia = r.copia || 0;
            if (copia) {
                log('#jl-bd-log', 'Copia previa abierta (número ' + copia + ').');
            } else {
                log('#jl-bd-log', 'Borrado directo, sin copia previa.');
            }

            function terminar(cortado) {
                post('cerrar_copia', { copia: copia }, function () {
                    bloquear('bd', false);
                    $('#jl-copias-recargar').click();
                    $('#jl-historial-recargar').click();
                    if (cortado) {
                        banner('#jl-bd-banner', 'warning', 'Limpieza detenida',
                            'Se han borrado ' + borradas + ' filas antes de parar.'
                            + (copia ? ' Lo guardado está en la pestaña Copias.' : ''));
                    } else if (errores) {
                        banner('#jl-bd-banner', 'warning', 'Limpieza terminada con avisos (' + segundos(inicio) + ')',
                            borradas + ' filas borradas, ' + errores + ' apartados fallaron. Mira el registro de abajo.');
                    } else {
                        banner('#jl-bd-banner', 'success', 'Limpieza terminada (' + segundos(inicio) + ')',
                            borradas + ' filas borradas'
                            + (copia ? ' y guardadas en la copia número ' + copia : ' (sin copia previa)')
                            + '. Para recuperar el espacio en disco, pasa por la pestaña Optimizar.');
                    }
                });
            }

            (function siguienteItem() {
                if (paused.bd) {
                    terminar(true);
                    return;
                }
                if (index >= items.length) {
                    terminar(false);
                    return;
                }
                var item = items[index++];
                var deEste = 0;

                (function lote() {
                    if (paused.bd) {
                        terminar(true);
                        return;
                    }
                    post('limpiar', { item: item.id, copia: copia, sincopia: copia ? 0 : 1 }, function (r) {
                        if (!r.ok) {
                            ++errores;
                            borradas += parseInt(r.deleted, 10) || 0;
                            log('#jl-bd-log', item.nombre + ': ' + r.error, 'error');
                            siguienteItem();
                            return;
                        }
                        deEste += parseInt(r.deleted, 10) || 0;
                        borradas += parseInt(r.deleted, 10) || 0;
                        if (totalFilas > 0) {
                            progreso('#jl-bd-progreso', borradas, totalFilas,
                                item.nombre + ': ' + deEste + ' filas — ' + borradas + ' de ' + totalFilas
                                + ' en ' + segundos(inicio));
                        } else {
                            progreso('#jl-bd-progreso', index, items.length,
                                item.nombre + ': ' + deEste + ' filas (total ' + borradas + ')');
                        }
                        $('#jl-bd .jl-count[data-item="' + item.id + '"]').html('<span class="text-muted">limpiando…</span>');
                        if (r.truncated) {
                            log('#jl-bd-log', item.nombre + ': tabla vaciada.', 'ok');
                            $('#jl-bd .jl-count[data-item="' + item.id + '"]').html('0');
                            siguienteItem();
                            return;
                        }
                        if (r.done) {
                            log('#jl-bd-log', item.nombre + ': ' + deEste + ' filas borradas.', 'ok');
                            $('#jl-bd .jl-count[data-item="' + item.id + '"]').html('0');
                            siguienteItem();
                            return;
                        }
                        lote();
                    });
                })();
            })();
        }

        if (conCopia) {
            post('abrir_copia', { tipo: 'bd', titulo: 'Limpieza de base de datos: ' + items.length + ' apartados' }, conCopiaAbierta);
        } else {
            conCopiaAbierta({ ok: true, copia: 0 });
        }
    });

    /* ------------------------------------------------------------------ */
    /* Imágenes                                                            */
    /* ------------------------------------------------------------------ */

    $(document).on('click', '.jl-escanear', function () {
        var reiniciar = $(this).data('reiniciar') ? 1 : 0;
        paused.img = false;
        bloquear('img', true);
        $('#jl-img-banner').hide();
        var inicio = new Date().getTime();
        var carpetas = 0;
        var archivos = 0;
        var hallados = 0;

        function lote() {
            if (paused.img) {
                bloquear('img', false);
                banner('#jl-img-banner', 'warning', 'Escáner en pausa',
                    'Se han revisado ' + carpetas + ' carpetas. Vuelve a darle a Escanear para seguir donde lo dejó.');
                recargarResumen();
                return;
            }
            post('img_lote', {}, function (r) {
                if (!r.ok) {
                    bloquear('img', false);
                    banner('#jl-img-banner', 'danger', 'El escáner se ha detenido', r.error);
                    return;
                }
                carpetas += parseInt(r.dirs, 10) || 0;
                archivos += parseInt(r.files, 10) || 0;
                hallados += parseInt(r.found, 10) || 0;
                if (r.phase === 'registros') {
                    $('#jl-img-estado').text('Comprobando las imágenes de la base de datos que no tienen archivo…');
                } else {
                    $('#jl-img-estado').text(carpetas + ' carpetas revisadas, ' + archivos
                        + ' archivos mirados, ' + hallados + ' huérfanas encontradas. Quedan ' + (parseInt(r.pending, 10) || 0) + ' carpetas.');
                }
                if (r.done) {
                    bloquear('img', false);
                    recargarResumen();
                    banner('#jl-img-banner', 'success', 'Escáner terminado (' + segundos(inicio) + ')',
                        carpetas + ' carpetas revisadas y ' + hallados + ' imágenes huérfanas encontradas. '
                        + 'Marca abajo los casos que quieras retirar: los archivos no se borran, se mueven a la cuarentena.');
                    return;
                }
                lote();
            });
        }

        if (reiniciar) {
            post('img_init', {}, function (r) {
                if (!r.ok) {
                    bloquear('img', false);
                    banner('#jl-img-banner', 'danger', 'No se pudo empezar el escáner', r.error);
                    return;
                }
                log('#jl-img-log', 'Escáner empezado de cero.');
                lote();
            });
        } else {
            lote();
        }
    });

    function recargarResumen() {
        post('img_resumen', {}, function (r) {
            if (r.ok) {
                $('#jl-img-resumen').html(r.html);
            }
        });
    }

    $(document).on('click', '.jl-ver-caso', function () {
        var caso = $(this).data('caso');
        $('#jl-img-visor').show().html('<p>Leyendo ejemplos…</p>');
        post('img_ejemplos', { caso: caso }, function (r) {
            $('#jl-img-visor').html(r.ok ? r.html : '<div class="alert alert-danger">' + escapar(r.error) + '</div>');
        });
    });

    $(document).on('click', '#jl-img-retirar', function () {
        if (!$('#jl-img-confirmo').is(':checked')) {
            alert('Marca antes que tienes una copia de seguridad completa del servidor.');
            return;
        }
        var casos = [];
        $('#jl-img-resumen .jl-caso:checked').each(function () {
            casos.push($(this).val());
        });
        if (!casos.length) {
            alert('No has marcado ningún caso de archivos.');
            return;
        }
        if (!confirm('Los archivos de ' + casos.length + ' caso(s) se mueven a la cuarentena del módulo.\n\n'
            + 'No se borran: desde la pestaña Copias se pueden devolver a su sitio. Pasados los días de retención, se borran solos.\n\n¿Seguir?')) {
            return;
        }

        paused.img = false;
        bloquear('img', true);
        $('#jl-img-banner').hide();
        var inicio = new Date().getTime();
        var movidos = 0;

        post('abrir_copia', { tipo: 'imagenes', titulo: 'Imágenes retiradas: ' + casos.join(', ') }, function (r) {
            if (!r.ok) {
                bloquear('img', false);
                banner('#jl-img-banner', 'danger', 'No se ha movido nada', r.error);
                return;
            }
            var copia = r.copia;

            function terminar(cortado, aviso) {
                post('cerrar_copia', { copia: copia }, function () {
                    bloquear('img', false);
                    recargarResumen();
                    $('#jl-copias-recargar').click();
                    $('#jl-historial-recargar').click();
                    if (cortado) {
                        banner('#jl-img-banner', 'warning', 'Proceso detenido',
                            movidos + ' archivos movidos a la cuarentena antes de parar.' + (aviso ? ' ' + aviso : ''));
                    } else {
                        banner('#jl-img-banner', 'success', 'Imágenes retiradas (' + segundos(inicio) + ')',
                            movidos + ' archivos movidos a la cuarentena (lote número ' + copia
                            + '). Comprueba la tienda; si falta algo, se devuelve desde la pestaña Copias.');
                    }
                });
            }

            (function lote() {
                if (paused.img) {
                    terminar(true, '');
                    return;
                }
                post('img_retirar', { copia: copia, casos: casos.join(',') }, function (r) {
                    if (!r.ok) {
                        terminar(true, r.error);
                        return;
                    }
                    movidos += parseInt(r.moved, 10) || 0;
                    $('#jl-img-estado').text(movidos + ' archivos movidos a la cuarentena.');
                    if (r.error) {
                        log('#jl-img-log', r.error, 'error');
                    }
                    if (r.done) {
                        terminar(false, '');
                        return;
                    }
                    lote();
                });
            })();
        });
    });

    $(document).on('click', '#jl-img-registros', function () {
        if (!$('#jl-img-confirmo').is(':checked')) {
            alert('Marca antes que tienes una copia de seguridad completa del servidor.');
            return;
        }
        if (!$('#jl-img-resumen .jl-caso-bd:checked').length) {
            alert('Marca el caso de las imágenes sin archivo para borrar sus registros.');
            return;
        }
        if (!confirm('Se van a borrar de la base de datos las imágenes cuyo archivo no está en el disco '
            + '(filas de image, image_lang e image_shop).\n\nSe guardan antes en una copia que se puede restaurar.\n\n¿Seguir?')) {
            return;
        }

        paused.img = false;
        bloquear('img', true);
        $('#jl-img-banner').hide();
        var inicio = new Date().getTime();
        var filas = 0;

        post('abrir_copia', { tipo: 'bd', titulo: 'Imágenes sin archivo borradas de la base de datos' }, function (r) {
            if (!r.ok) {
                bloquear('img', false);
                banner('#jl-img-banner', 'danger', 'No se ha borrado nada', r.error);
                return;
            }
            var copia = r.copia;

            (function lote() {
                if (paused.img) {
                    post('cerrar_copia', { copia: copia }, function () {
                        bloquear('img', false);
                        recargarResumen();
                        banner('#jl-img-banner', 'warning', 'Proceso detenido', filas + ' filas borradas antes de parar.');
                    });
                    return;
                }
                post('img_registros', { copia: copia }, function (r) {
                    if (!r.ok) {
                        post('cerrar_copia', { copia: copia }, function () {
                            bloquear('img', false);
                            banner('#jl-img-banner', 'danger', 'El proceso se ha detenido', r.error);
                        });
                        return;
                    }
                    filas += parseInt(r.deleted, 10) || 0;
                    $('#jl-img-estado').text(filas + ' filas borradas de la base de datos.');
                    if (r.done) {
                        post('cerrar_copia', { copia: copia }, function () {
                            bloquear('img', false);
                            recargarResumen();
                            $('#jl-copias-recargar').click();
                            $('#jl-historial-recargar').click();
                            banner('#jl-img-banner', 'success', 'Registros limpiados (' + segundos(inicio) + ')',
                                filas + ' filas borradas y guardadas en la copia número ' + copia + '.');
                        });
                        return;
                    }
                    lote();
                });
            })();
        });
    });

    /* ------------------------------------------------------------------ */
    /* Optimizar                                                           */
    /* ------------------------------------------------------------------ */

    $(document).on('click', '#jl-tablas-cargar', function () {
        var $boton = $(this).prop('disabled', true);
        $('#jl-tablas').html('<p>Leyendo el estado de las tablas…</p>');
        post('tablas', {}, function (r) {
            $boton.prop('disabled', false);
            $('#jl-tablas').html(r.ok ? r.html : '<div class="alert alert-danger">' + escapar(r.error) + '</div>');
        });
    });

    $(document).on('change', '#jl-tablas-todas', function () {
        $('#jl-tablas .jl-tabla').prop('checked', $(this).is(':checked'));
    });

    $(document).on('click', '#jl-optimizar', function () {
        var tablas = [];
        $('#jl-tablas .jl-tabla:checked').each(function () {
            tablas.push($(this).val());
        });
        if (!tablas.length) {
            alert('No has marcado ninguna tabla.');
            return;
        }
        if (!confirm('Se van a optimizar ' + tablas.length + ' tabla(s), una a una.\n\n'
            + 'Mientras se optimiza una tabla, la tienda no puede escribir en ella, y el servidor necesita en disco libre '
            + 'al menos lo que pese la tabla más grande. Hazlo con la tienda tranquila.\n\n¿Seguir?')) {
            return;
        }

        paused.opt = false;
        bloquear('opt', true);
        $('#jl-opt-banner').hide();
        var inicio = new Date().getTime();
        var index = 0;
        var liberado = 0;
        var errores = 0;

        (function siguiente() {
            if (paused.opt || index >= tablas.length) {
                bloquear('opt', false);
                banner('#jl-opt-banner', errores ? 'warning' : 'success',
                    (paused.opt ? 'Optimización detenida' : 'Optimización terminada') + ' (' + segundos(inicio) + ')',
                    index + ' de ' + tablas.length + ' tablas, ' + Math.round(liberado / 1048576 * 100) / 100
                    + ' MB recuperados' + (errores ? ', ' + errores + ' con error (mira el registro)' : '') + '.');
                return;
            }
            var tabla = tablas[index++];
            progreso('#jl-opt-progreso', index, tablas.length, 'Optimizando ' + tabla + '…');
            post('optimizar', { tabla: tabla }, function (r) {
                if (r.ok) {
                    liberado += parseFloat(r.saved) || 0;
                    log('#jl-opt-log', tabla + ': recuperados ' + (Math.round((parseFloat(r.saved) || 0) / 1024) ) + ' KB.', 'ok');
                } else {
                    ++errores;
                    log('#jl-opt-log', r.error, 'error');
                }
                siguiente();
            });
        })();
    });

    /* ------------------------------------------------------------------ */
    /* Copias e historial                                                  */
    /* ------------------------------------------------------------------ */

    $(document).on('click', '#jl-copias-recargar', function () {
        post('copias', {}, function (r) {
            if (r.ok) {
                $('#jl-copias-lista').html(r.html);
            }
        });
    });

    $(document).on('click', '#jl-historial-recargar', function () {
        post('historial', {}, function (r) {
            if (r.ok) {
                $('#jl-historial').html(r.html);
            }
        });
    });

    $(document).on('click', '.jl-restaurar', function () {
        if (!confirm('Se devuelve a la tienda lo que guarda esta copia.\n\n'
            + 'Las filas se vuelven a insertar y los archivos vuelven a su sitio en img/. ¿Seguir?')) {
            return;
        }
        var $boton = $(this).prop('disabled', true);
        var copia = $boton.data('copia');
        $('#jl-copias-estado').html('Restaurando…');
        post('restaurar', { copia: copia }, function (r) {
            $boton.prop('disabled', false);
            $('#jl-copias-estado').html(r.ok
                ? '<div class="alert alert-success">' + escapar(r.message) + '</div>'
                : '<div class="alert alert-danger">' + escapar(r.error) + '</div>');
            $('#jl-copias-recargar').click();
            $('#jl-historial-recargar').click();
        });
    });

    $(document).on('click', '.jl-borrar-copia', function () {
        if (!confirm('Esta copia se borra para siempre y ya no se podrá restaurar. ¿Seguir?')) {
            return;
        }
        var copia = $(this).data('copia');
        post('borrar_copia', { copia: copia }, function (r) {
            $('#jl-copias-estado').html(r.ok ? '' : '<div class="alert alert-danger">' + escapar(r.error) + '</div>');
            $('#jl-copias-recargar').click();
        });
    });

    /* ------------------------------------------------------------------ */
    /* Pausa                                                               */
    /* ------------------------------------------------------------------ */

    $(document).on('click', '.jl-pausa', function () {
        var scope = $(this).data('scope');
        paused[scope] = true;
        $(this).prop('disabled', true).text('Parando al terminar el lote…');
    });

    $(window).on('beforeunload', function () {
        if (running.bd || running.img || running.opt) {
            return 'Hay un proceso en marcha. Si sales ahora se queda a medias (lo hecho hasta ahora está guardado).';
        }
    });
}(jQuery));
