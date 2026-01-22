<?php
namespace Cloudari\Onebox\Domain\ManualEvents;

final class PostType
{
    public const SLUG = 'evento_manual';

    public static function register(): void
    {
        $labels = [
            'name'               => 'Eventos manuales',
            'singular_name'      => 'Evento manual',
            'menu_name'          => 'Eventos manuales',
            'add_new'            => 'Añadir nuevo',
            'add_new_item'       => 'Añadir evento manual',
            'edit_item'          => 'Editar evento manual',
            'new_item'           => 'Nuevo evento manual',
            'view_item'          => 'Ver evento manual',
            'search_items'       => 'Buscar eventos manuales',
            'not_found'          => 'No se han encontrado eventos',
            'not_found_in_trash' => 'No hay eventos en la papelera',
        ];

        $args = [
            'labels'          => $labels,
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => true,
            'menu_position'   => 20,
            'menu_icon'       => 'dashicons-tickets-alt',
            'capability_type' => 'post',
            'map_meta_cap'    => true,
            'supports'        => ['title'],
        ];

        register_post_type(self::SLUG, $args);
    }
}
