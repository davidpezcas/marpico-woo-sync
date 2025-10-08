<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marpico_Price {

    public function __construct() {
        add_action('wp_ajax_marpico_aplicar_aumento', [ $this, 'ajax_aplicar_aumento' ]);
    }

    public function ajax_aplicar_aumento() {
        error_log('Marpico_Price -> AJAX ejecutado correctamente');
        error_log('Marpico_Price::ajax_aplicar_aumento POST=' . print_r($_POST, true));

        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos suficientes.');
        }

        check_ajax_referer('marpico_sync_nonce', 'security');

        $incremento = floatval($_POST['incremento'] ?? 0);
        $excluidas  = isset($_POST['categoriasExcluidas']) ? array_map('intval', (array)$_POST['categoriasExcluidas']) : [];

        if ($incremento === 0) {
            wp_send_json_error('El incremento no puede ser 0.');
        }

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [],
        ];

        if (!empty($excluidas)) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $excluidas,
                'operator' => 'NOT IN',
            ];
        }

        $productos = get_posts($args);
        error_log('Productos encontrados: ' . count($productos));


        foreach ($productos as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            // Si el producto es simple
            if ($product->is_type('simple')) {
                $precio_actual = (float) $product->get_regular_price() ?: 0;
                $nuevo_precio  = $precio_actual + $incremento;
                $product->set_regular_price($nuevo_precio);
                $product->save();
                error_log("Producto simple actualizado: ID {$product_id} -> {$nuevo_precio}");
            }

            // Si el producto es variable
            elseif ($product->is_type('variable')) {
                $variaciones = $product->get_children();

                foreach ($variaciones as $variacion_id) {
                    $variacion = wc_get_product($variacion_id);
                    if ($variacion) {
                        $precio_actual = (float) $variacion->get_regular_price() ?: 0;
                        $nuevo_precio  = $precio_actual + $incremento;
                        $variacion->set_regular_price($nuevo_precio);
                        $variacion->save();
                        error_log("Variación actualizada: ID {$variacion_id} -> {$nuevo_precio}");
                    }
                }

                // Recalcular el rango de precios del producto padre
                $product->variable_product_sync();
                $product->save();
                error_log("Producto variable sincronizado: ID {$product_id}");
            }
        }

        wp_send_json_success('Precios actualizados correctamente.');
    }
}
