<?php
/**
 * CRUD de Serviços com validação
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_Services {

    public static function get_all(): array {
        return BarberPro_Database::get_services();
    }

    public static function get( int $id ): ?object {
        return BarberPro_Database::get_service( $id );
    }

    public static function create( array $data ): array {
        if ( ! current_user_can( 'barberpro_manage_services' ) ) {
            return [ 'success' => false, 'message' => __( 'Sem permissão.', 'barberpro-saas' ) ];
        }
        if ( empty( $data['name'] ) || empty( $data['price'] ) || empty( $data['duration'] ) ) {
            return [ 'success' => false, 'message' => __( 'Nome, preço e duração são obrigatórios.', 'barberpro-saas' ) ];
        }
        $id = BarberPro_Database::insert_service( $data );
        return $id
            ? [ 'success' => true, 'id' => $id ]
            : [ 'success' => false, 'message' => __( 'Erro ao criar serviço.', 'barberpro-saas' ) ];
    }

    public static function update( int $id, array $data ): array {
        if ( ! current_user_can( 'barberpro_manage_services' ) ) {
            return [ 'success' => false, 'message' => __( 'Sem permissão.', 'barberpro-saas' ) ];
        }
        $ok = BarberPro_Database::update_service( $id, $data );
        return $ok
            ? [ 'success' => true ]
            : [ 'success' => false, 'message' => __( 'Erro ao atualizar serviço.', 'barberpro-saas' ) ];
    }

    public static function delete( int $id ): array {
        if ( ! current_user_can( 'barberpro_manage_services' ) ) {
            return [ 'success' => false, 'message' => __( 'Sem permissão.', 'barberpro-saas' ) ];
        }
        BarberPro_Database::delete_service( $id );
        return [ 'success' => true ];
    }
}
