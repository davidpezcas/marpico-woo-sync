<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BestStock_Sync {

    private $client;

    public function __construct() {
        $this->client = new Beststock_Client();
    }

    /**
     * Handler AJAX para sincronizar productos.
     */
    public function ajax_beststock_sync_products($categoria_id, $wc_categories = []) {

        // Compatibilidad hacia atrás: si viene el $_POST['wc_category'] viejo
        if (empty($wc_categories)) {
            if (isset($_POST['wc_category'])) {
                $wc_categories = [intval($_POST['wc_category'])];
            } else {
                $wc_categories = [];
            }
        }

        // --- Obtener productos de la categoría ---
        $products = $this->client->get_products_by_category( $categoria_id );
        if ( is_wp_error( $products ) ) {
            error_log("Error al obtener productos: " . $products->get_error_message());
            return $products;
        }

        if ( empty( $products ) || ! is_array( $products ) ) {
            return new WP_Error( 'no_data', 'No se encontraron datos para la categoría ' . $categoria_id );
        }

        $saved_count = 0;

        foreach ($products as $prod) {
            error_log("Procesando producto: " . $prod['name'] . " (ID API: " . $prod['id'] . ")");

            try {
                // --- Verificar si existe el producto por SKU/ID externo ---
                $existing = wc_get_product_id_by_sku($prod['id']);
                if ($existing) {
                    $product = wc_get_product($existing);
                    error_log("Producto existente encontrado: {$prod['name']} (ID Woo: $existing)");
                } else {
                    error_log("Creando nuevo producto: {$prod['name']}");
                }

                // --- Pasamos array de categorías en vez de solo una ---
                $this->create_or_update_product($prod, $wc_categories);
                $saved_count++;

            } catch (Exception $e) {
                error_log("Error guardando producto {$prod['name']}: " . $e->getMessage());
            }
        }


        wp_send_json_success([
            'message' => "Productos sincronizados: $saved_count",
            'data' => $products
        ]);
    }


    private function create_or_update_product($prod, $wc_category = 0) {
        if ( ! class_exists('WC_Product') ) return false;

        // --- Verificar si ya existe producto por ID externo ---
        $existing = get_posts([
            'post_type'  => 'product',
            'meta_key'   => '_beststock_id',
            'meta_value' => $prod['id'],
            'posts_per_page' => 1
        ]);

        if ( $existing ) {
            $product_id = $existing[0]->ID;
            $product = wc_get_product($product_id);
            error_log("Producto existente actualizado: {$prod['name']} (Woo ID: $product_id)");
        } else {
            // Si tiene colores → variable, si no → simple
            if ( !empty($prod['colors']) && is_array($prod['colors']) ) {
                $product = new WC_Product_Variable();
            } else {
                $product = new WC_Product_Simple();
            }
            $product->set_sku($prod['id']);
            error_log("Creando producto nuevo: {$prod['name']}");
        }

        // --- Datos básicos ---
        $product->set_name($prod['name']);
        $product->set_description($prod['description'] ?? '');
        if ( empty($prod['colors']) ) {
            if ( !empty($prod['price_scale'][0]['price']) ) {
                $product->set_regular_price($prod['price_scale'][0]['price']);
            }
        }
        $product_id = $product->save();

        // Guardar ID externo
        update_post_meta($product_id, '_beststock_id', $prod['id']);
        
        // --- Asignar categorías padre + hija ---
        $terms = [];

        if (!empty($_POST['wc_category_parent'])) {
            $terms[] = intval($_POST['wc_category_parent']); // padre
        }

        if (!empty($_POST['wc_category_child'])) {
            $terms[] = intval($_POST['wc_category_child']); // hija
        }

        // Limpiar duplicados y vacíos
        $terms = array_unique(array_filter($terms));

        // Excluir la categoría predeterminada
        $default_cat = get_option('default_product_cat');
        $terms = array_diff($terms, [$default_cat]);

        // Si hay categorías válidas, asignarlas
        if (!empty($terms)) {
            wp_set_object_terms($product_id, $terms, 'product_cat', true);
        } else {
            // Si no hay categorías válidas, limpiar todas menos la predeterminada
            wp_set_object_terms($product_id, [], 'product_cat', true);
        }


        // --- Imagen destacada (ONLY basic_picture, NO agregarla a la galería) ---
        if ( ! empty($prod['basic_picture']) ) {
            // set_product_image retorna attachment_id y asigna como thumbnail al post pasado
            $thumb_id = $this->set_product_image($product_id, $prod['basic_picture']);
            if ($thumb_id) {
                // Aseguramos que la imagen destacada es la basic_picture (pero NO la añadimos a la galería)
                set_post_thumbnail($product_id, $thumb_id);
            }
        }

        // --- Inicializar galería para acumular imágenes nuevas (solo imágenes de variantes) ---
        $gallery_ids = get_post_meta( $product_id, '_product_image_gallery', true );
        $gallery_ids = $gallery_ids ? explode(',', $gallery_ids) : [];

        // Filtrar solo IDs que existan en la base de datos
        $gallery_ids = array_filter($gallery_ids, function($id) {
            return get_post_status($id) !== false; // Si no existe, lo elimina del array
        });

        // Guardar de nuevo los IDs limpios
        update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));

        // --- Si tiene colores: atributos + variaciones ---
        if ( !empty($prod['colors']) && is_array($prod['colors']) ) {
            $taxonomy = 'pa_color';
            if ( ! taxonomy_exists($taxonomy) ) {
                register_taxonomy(
                    $taxonomy,
                    'product',
                    [
                        'label' => 'Color',
                        'rewrite' => ['slug' => 'color'],
                        'hierarchical' => false,
                    ]
                );
            }

            $names = [];
            foreach ($prod['colors'] as $c) {
                $names[] = sanitize_text_field($c['color']);
            }

            wp_set_object_terms($product_id, $names, $taxonomy);

            $attribute_data[$taxonomy] = [
                'name' => $taxonomy,
                'value' => implode('|', $names),
                'position' => 0,
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 1
            ];
            update_post_meta($product_id, '_product_attributes', $attribute_data);

            // --- Crear variaciones: pasar $gallery_ids por referencia para acumular ahí las imágenes ---
            foreach ($prod['colors'] as $c) {
                $variation_id = $this->create_or_update_variation($product_id, $taxonomy, $c, $gallery_ids);
                if ($variation_id) {
                    error_log("Variación creada/actualizada: {$c['color']} (ID: $variation_id)");
                }
            }
        }

        // --- Normalizar y guardar la galería final (sin duplicados y sin la featured) ---
        $gallery_ids = array_map('intval', $gallery_ids);
        // Quitar thumbnail si por alguna razón está en la lista (queremos que basic_picture NO esté en la galería)
        $featured = get_post_thumbnail_id($product_id);
        if ($featured) {
            $gallery_ids = array_filter($gallery_ids, function($id) use ($featured) {
                return intval($id) !== intval($featured);
            });
        }
        $gallery_ids = array_unique(array_filter($gallery_ids));
        if (!empty($gallery_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        } else {
            delete_post_meta($product_id, '_product_image_gallery');
        }

        return true;
    }

    /**
     * Crear o actualizar variación.
     */
    private function create_or_update_variation($product_id, $taxonomy, $color, &$gallery_ids) {
        $args = [
            'post_type'   => 'product_variation',
            'post_parent' => $product_id,
            'meta_query'  => [
                [
                    'key'   => 'attribute_' . $taxonomy,
                    'value' => sanitize_title($color['color'])
                ]
            ]
        ];
        $existing = get_posts($args);

        if ($existing) {
            $variation_id = $existing[0]->ID;
            $variation = new WC_Product_Variation($variation_id);
        } else {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
        }

        $variation->set_attributes([ $taxonomy => sanitize_title($color['color']) ]);
        $variation->set_regular_price(0); // precio 0

        // --- Stock ---
        if (isset($color['stock'])) {
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity(intval($color['stock']));
            $variation->set_stock_status( ( intval($color['stock']) > 0 ) ? 'instock' : 'outofstock' );
        }

        $variation_id = $variation->save();

        // --- Imagen de la variación (usar primera imagen del array) ---
        if (!empty($color['images'][0])) {
            $image_url = $color['images'][0];

            // Reutilizar attachment si ya existe o descargar y adjuntar usando set_product_image
            // set_product_image busca meta '_beststock_image' antes de descargar, así evita duplicados.
            $image_id = $this->set_product_image($variation_id, $image_url);

            if ($image_id && ! is_wp_error($image_id)) {
                // Asegurar que la variación tenga su thumbnail
                set_post_thumbnail($variation_id, $image_id);

                // Agregar esa imagen a la galería del producto padre (sin duplicados)
                $gallery_ids = $gallery_ids ? $gallery_ids : [];
                $gallery_ids = array_map('intval', $gallery_ids);
                if (!in_array(intval($image_id), $gallery_ids, true)) {
                    $gallery_ids[] = intval($image_id);
                }
            }
        }

        return $variation_id;
    }

    /**
     * Setear imagen y devolver ID.
     */
    private function set_product_image($post_id, $url) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $existing = get_posts([
            'post_type' => 'attachment',
            'meta_key' => '_beststock_image',
            'meta_value' => $url,
            'posts_per_page' => 1
        ]);

        if ( $existing ) {
            set_post_thumbnail($post_id, $existing[0]->ID);
            return $existing[0]->ID;
        }

        $tmp = download_url($url);
        if ( is_wp_error($tmp) ) return 0;

        $file_array = [
            'name' => basename($url),
            'tmp_name' => $tmp
        ];

        $id = media_handle_sideload($file_array, $post_id);
        if ( ! is_wp_error($id) ) {
            set_post_thumbnail($post_id, $id);
            update_post_meta($id, '_beststock_image', $url);
            return $id;
        }

        return 0;
    }

}
