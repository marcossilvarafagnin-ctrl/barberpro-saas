<?php
/**
 * Instalação e migração do banco de dados
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_Installer {

    public static function activate(): void {
        self::create_tables();
        BarberPro_Roles::create_roles();
        self::create_default_company();
        self::seed_expense_categories();
        update_option( 'barberpro_db_version', BARBERPRO_DB_VERSION );
        self::seed_vehicle_variants();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'barberpro_send_reminders' );
        wp_clear_scheduled_hook( 'barberpro_kanban_auto' );
        flush_rewrite_rules();
    }

    /** Executa upgrade automático quando a versão do DB muda */
    public static function maybe_upgrade(): void {
        $installed = get_option( 'barberpro_db_version', '0' );
        if ( version_compare( $installed, BARBERPRO_DB_VERSION, '<' ) ) {
            self::create_tables();
            self::seed_expense_categories();
            self::seed_vehicle_variants();
            update_option( 'barberpro_db_version', BARBERPRO_DB_VERSION );
        }
        // Garante sempre estrutura básica e permissões, independente de versão
        self::create_default_company();   // cria empresa padrão se não existir
        BarberPro_Roles::create_roles();  // garante caps do administrator
        self::maybe_migrate();            // adiciona colunas novas em sites existentes
    }

    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        // ── Companies ────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_companies (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name       VARCHAR(150) NOT NULL,
            slug       VARCHAR(100) NOT NULL,
            email      VARCHAR(150) DEFAULT NULL,
            phone      VARCHAR(20)  DEFAULT NULL,
            address    TEXT         DEFAULT NULL,
            logo       VARCHAR(255) DEFAULT NULL,
            cnpj       VARCHAR(20)  DEFAULT NULL,
            plan       VARCHAR(50)  NOT NULL DEFAULT 'free',
            status     ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) {$charset};" );

        // ── Professionals ─────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_professionals (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id     INT UNSIGNED NOT NULL DEFAULT 1,
            user_id        BIGINT UNSIGNED DEFAULT NULL,
            name           VARCHAR(100) NOT NULL,
            specialty      VARCHAR(100) DEFAULT NULL,
            photo          VARCHAR(255) DEFAULT NULL,
            phone          VARCHAR(20)  DEFAULT NULL,
            bio            TEXT         DEFAULT NULL,
            work_days      VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5',
            work_start     TIME         NOT NULL DEFAULT '09:00:00',
            work_end       TIME         NOT NULL DEFAULT '18:00:00',
            lunch_start    TIME         NOT NULL DEFAULT '12:00:00',
            lunch_end      TIME         NOT NULL DEFAULT '13:00:00',
            slot_interval        INT          NOT NULL DEFAULT 15,
            client_slot_interval INT          NOT NULL DEFAULT 60,
            avg_return_days      INT          NOT NULL DEFAULT 0,
            commission_pct DECIMAL(5,2) NOT NULL DEFAULT 40.00,
            commission_type ENUM('percentual','fixo') NOT NULL DEFAULT 'percentual',
            rating         DECIMAL(3,2) NOT NULL DEFAULT 0.00,
            rating_count   INT          NOT NULL DEFAULT 0,
            monthly_goal   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at     DATETIME NOT NULL,
            updated_at     DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY user_id (user_id)
        ) {$charset};" );

        // Garante empresas padrao (barbearia=1, lavacar=2, bar=3)
        $wpdb->query(
            "INSERT IGNORE INTO {$p}barber_companies (id, name, slug, plan, status, created_at)
             VALUES (1, 'Barbearia', 'barbearia', 'pro', 'active', NOW()),
                    (2, 'Lava-Car',  'lavacar',   'pro', 'active', NOW()),
                    (3, 'Bar/Eventos','bar',       'pro', 'active', NOW())"
        );

        // ── Services ──────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_services (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id  INT UNSIGNED NOT NULL DEFAULT 1,
            name        VARCHAR(150) NOT NULL,
            description TEXT         DEFAULT NULL,
            price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            duration    INT          NOT NULL DEFAULT 30,
            photo       VARCHAR(255) DEFAULT NULL,
            category    VARCHAR(80)  DEFAULT NULL,
            status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id)
        ) {$charset};" );

        // ── Service Variants (preços por porte/variante) ─────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_service_variants (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            service_id  INT UNSIGNED NOT NULL,
            company_id  INT UNSIGNED NOT NULL DEFAULT 1,
            name        VARCHAR(80)  NOT NULL,
            size_key    VARCHAR(20)  DEFAULT NULL,
            price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            duration    INT          DEFAULT NULL,
            sort_order  INT          NOT NULL DEFAULT 0,
            status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY service_id (service_id),
            KEY company_id (company_id)
        ) {$charset};" );

        // ── Bookings ──────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_bookings (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id       INT UNSIGNED NOT NULL DEFAULT 1,
            service_id       INT UNSIGNED NOT NULL,
            professional_id  INT UNSIGNED NOT NULL,
            client_user_id   BIGINT UNSIGNED DEFAULT NULL,
            client_name      VARCHAR(100) NOT NULL,
            client_phone     VARCHAR(20)  NOT NULL,
            client_email     VARCHAR(150) DEFAULT NULL,
            booking_date     DATE         NOT NULL,
            booking_time     TIME         NOT NULL,
            status           ENUM('agendado','confirmado','em_atendimento','finalizado','cancelado','recusado','lista_espera') NOT NULL DEFAULT 'agendado',
            payment_method   ENUM('presencial','online','pix','dinheiro','cartao','transferencia') NOT NULL DEFAULT 'presencial',
            payment_status   ENUM('pendente','pago','parcial','reembolsado') NOT NULL DEFAULT 'pendente',
            amount_total     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            amount_paid      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            notes            TEXT         DEFAULT NULL,
            booking_code     VARCHAR(20)  NOT NULL,
            loyalty_points   INT          NOT NULL DEFAULT 0,
            reminder_sent    TINYINT(1)   NOT NULL DEFAULT 0,
            vehicle_plate    VARCHAR(10)  DEFAULT NULL,
            vehicle_model    VARCHAR(80)  DEFAULT NULL,
            vehicle_color    VARCHAR(40)  DEFAULT NULL,
            vehicle_size     VARCHAR(20)  DEFAULT NULL,
            service_variant  VARCHAR(80)  DEFAULT NULL,
            amount_variant   DECIMAL(10,2) DEFAULT NULL,
            delivery_type    ENUM('cliente_traz','empresa_busca_entrega','empresa_busca_cliente_retira','cliente_leva_empresa_entrega') DEFAULT NULL,
            delivery_address TEXT         DEFAULT NULL,
            delivery_fee     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            delivery_notes   VARCHAR(255) DEFAULT NULL,
            created_at       DATETIME     NOT NULL,
            updated_at       DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY booking_code (booking_code),
            KEY company_id (company_id),
            KEY professional_id (professional_id),
            KEY booking_date (booking_date),
            KEY status (status)
        ) {$charset};" );

        // ── Finance – Tabela principal de lançamentos ──────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_finance (
            id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id        INT UNSIGNED NOT NULL DEFAULT 1,
            booking_id        INT UNSIGNED DEFAULT NULL,
            type              ENUM('receita','despesa') NOT NULL,
            category_id       INT UNSIGNED DEFAULT NULL,
            subcategory       VARCHAR(100) DEFAULT NULL,
            description       VARCHAR(255) NOT NULL,
            amount            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_method    ENUM('dinheiro','pix','cartao_debito','cartao_credito','transferencia','boleto','cheque','outro') NOT NULL DEFAULT 'dinheiro',
            status            ENUM('pago','pendente','vencido','cancelado') NOT NULL DEFAULT 'pago',
            competencia_date  DATE         NOT NULL,
            due_date          DATE         DEFAULT NULL,
            paid_at           DATETIME     DEFAULT NULL,
            professional_id   INT UNSIGNED DEFAULT NULL,
            cost_center       VARCHAR(80)  DEFAULT NULL,
            supplier          VARCHAR(150) DEFAULT NULL,
            invoice_number    VARCHAR(50)  DEFAULT NULL,
            is_recurring      TINYINT(1)   NOT NULL DEFAULT 0,
            recurring_group   VARCHAR(36)  DEFAULT NULL,
            tags              VARCHAR(255) DEFAULT NULL,
            notes             TEXT         DEFAULT NULL,
            attachment        VARCHAR(255) DEFAULT NULL,
            created_by        BIGINT UNSIGNED DEFAULT NULL,
            created_at        DATETIME NOT NULL,
            updated_at        DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY type (type),
            KEY status (status),
            KEY competencia_date (competencia_date),
            KEY due_date (due_date),
            KEY category_id (category_id),
            KEY professional_id (professional_id),
            KEY booking_id (booking_id)
        ) {$charset};" );

        // ── Finance Categories – Plano de contas configurável ─────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_finance_categories (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id  INT UNSIGNED NOT NULL DEFAULT 1,
            type        ENUM('receita','despesa') NOT NULL,
            name        VARCHAR(100) NOT NULL,
            code        VARCHAR(20)  DEFAULT NULL,
            color       VARCHAR(7)   DEFAULT '#6b7280',
            icon        VARCHAR(50)  DEFAULT NULL,
            is_system   TINYINT(1)   NOT NULL DEFAULT 0,
            status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
            sort_order  INT          NOT NULL DEFAULT 0,
            created_at  DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY company_id_type (company_id, type),
            KEY is_system (is_system)
        ) {$charset};" );

        // ── Commissions ───────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_commissions (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id      INT UNSIGNED NOT NULL DEFAULT 1,
            professional_id INT UNSIGNED NOT NULL,
            booking_id      INT UNSIGNED NOT NULL,
            finance_id      INT UNSIGNED DEFAULT NULL,
            gross_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            pct             DECIMAL(5,2)  NOT NULL DEFAULT 40.00,
            amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status          ENUM('pendente','pago','cancelado') NOT NULL DEFAULT 'pendente',
            paid_at         DATETIME     DEFAULT NULL,
            notes           VARCHAR(255) DEFAULT NULL,
            created_at      DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY professional_id (professional_id),
            KEY status (status),
            KEY booking_id (booking_id)
        ) {$charset};" );

        // ── Finance Budget – Orçamento mensal por categoria ───────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_finance_budget (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id  INT UNSIGNED NOT NULL DEFAULT 1,
            category_id INT UNSIGNED NOT NULL,
            `year_month` VARCHAR(7)   NOT NULL,
            budget      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at  DATETIME     NOT NULL,
            updated_at  DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY cat_month (company_id, category_id, `year_month`)
        ) {$charset};" );


        // ── Comandas ──────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_comandas (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id      INT UNSIGNED NOT NULL DEFAULT 1,
            comanda_code    VARCHAR(20)  NOT NULL,
            client_name     VARCHAR(100) NOT NULL,
            client_phone    VARCHAR(20)  DEFAULT NULL,
            status          ENUM('aberta','fechada','paga','cancelada') NOT NULL DEFAULT 'aberta',
            discount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_type   ENUM('fixo','percentual') NOT NULL DEFAULT 'fixo',
            total_items     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_final     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            notes           TEXT         DEFAULT NULL,
            opened_by       BIGINT UNSIGNED DEFAULT NULL,
            closed_at       DATETIME     DEFAULT NULL,
            paid_at         DATETIME     DEFAULT NULL,
            created_at      DATETIME     NOT NULL,
            updated_at      DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY comanda_code (comanda_code),
            KEY company_id (company_id),
            KEY status (status)
        ) {$charset};" );

        // ── Comanda Items ─────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_comanda_items (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            comanda_id      INT UNSIGNED NOT NULL,
            company_id      INT UNSIGNED NOT NULL DEFAULT 1,
            professional_id INT UNSIGNED DEFAULT NULL,
            service_id      INT UNSIGNED DEFAULT NULL,
            description     VARCHAR(200) NOT NULL,
            quantity        DECIMAL(8,2) NOT NULL DEFAULT 1.00,
            unit_price      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_price     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            item_type       ENUM('servico','produto','livre') NOT NULL DEFAULT 'servico',
            created_at      DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY comanda_id (comanda_id),
            KEY professional_id (professional_id)
        ) {$charset};" );

        // ── Comanda Payments (split) ──────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_comanda_payments (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            comanda_id      INT UNSIGNED NOT NULL,
            company_id      INT UNSIGNED NOT NULL DEFAULT 1,
            payment_method  ENUM('dinheiro','pix','cartao_debito','cartao_credito') NOT NULL,
            amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at      DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY comanda_id (comanda_id)
        ) {$charset};" );


        // ── Produtos (estoque do bar) ─────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_products (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id      INT UNSIGNED NOT NULL DEFAULT 3,
            name            VARCHAR(150) NOT NULL,
            description     TEXT         DEFAULT NULL,
            category        VARCHAR(80)  DEFAULT NULL,
            sku             VARCHAR(60)  DEFAULT NULL,
            barcode         VARCHAR(60)  DEFAULT NULL,
            unit            VARCHAR(20)  NOT NULL DEFAULT 'un',
            cost_price      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            sale_price      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock_qty       DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            stock_min       DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            stock_max       DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at      DATETIME     NOT NULL,
            updated_at      DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY category (category)
        ) {$charset};" );

        // ── Movimentacoes de estoque ──────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_stock_moves (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id      INT UNSIGNED NOT NULL,
            company_id      INT UNSIGNED NOT NULL DEFAULT 3,
            move_type       ENUM('entrada','saida','ajuste','transferencia') NOT NULL,
            qty             DECIMAL(10,3) NOT NULL,
            qty_before      DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            qty_after       DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            unit_cost       DECIMAL(10,2) DEFAULT NULL,
            supplier        VARCHAR(150) DEFAULT NULL,
            invoice_number  VARCHAR(50)  DEFAULT NULL,
            reason          VARCHAR(200) DEFAULT NULL,
            origin_company  INT UNSIGNED DEFAULT NULL,
            dest_company    INT UNSIGNED DEFAULT NULL,
            comanda_id      INT UNSIGNED DEFAULT NULL,
            created_by      BIGINT UNSIGNED DEFAULT NULL,
            created_at      DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY move_type (move_type),
            KEY comanda_id (comanda_id)
        ) {$charset};" );

        // ── Comandas do bar ───────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_bar_comandas (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id      INT UNSIGNED NOT NULL DEFAULT 3,
            comanda_code    VARCHAR(20)  NOT NULL,
            table_number    VARCHAR(20)  DEFAULT NULL,
            client_name     VARCHAR(100) DEFAULT NULL,
            status          ENUM('aberta','fechada','paga','cancelada') NOT NULL DEFAULT 'aberta',
            discount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_type   ENUM('fixo','percentual') NOT NULL DEFAULT 'fixo',
            total_items     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_final     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            notes           TEXT         DEFAULT NULL,
            opened_by       BIGINT UNSIGNED DEFAULT NULL,
            paid_at         DATETIME     DEFAULT NULL,
            created_at      DATETIME     NOT NULL,
            updated_at      DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY comanda_code (comanda_code),
            KEY company_id (company_id),
            KEY status (status),
            KEY table_number (table_number)
        ) {$charset};" );

        // ── Itens das comandas do bar ─────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_bar_comanda_items (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            comanda_id      INT UNSIGNED NOT NULL,
            company_id      INT UNSIGNED NOT NULL DEFAULT 3,
            product_id      INT UNSIGNED NOT NULL,
            product_name    VARCHAR(150) NOT NULL,
            quantity        DECIMAL(8,3) NOT NULL DEFAULT 1.000,
            unit_price      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_price     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at      DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY comanda_id (comanda_id),
            KEY product_id (product_id)
        ) {$charset};" );

        // ── Pagamentos das comandas do bar ────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_bar_payments (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            comanda_id      INT UNSIGNED NOT NULL,
            company_id      INT UNSIGNED NOT NULL DEFAULT 3,
            payment_method  ENUM('dinheiro','pix','cartao_debito','cartao_credito') NOT NULL,
            amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at      DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY comanda_id (comanda_id)
        ) {$charset};" );

        // ── Settings ──────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}barber_settings (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id    INT UNSIGNED NOT NULL DEFAULT 1,
            setting_key   VARCHAR(100) NOT NULL,
            setting_value LONGTEXT     DEFAULT NULL,
            created_at    DATETIME     NOT NULL,
            updated_at    DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY company_setting (company_id, setting_key)
        ) {$charset};" );
    }

    // ── Empresa padrão ────────────────────────────────────────────────────────
    private static function create_default_company(): void {
        global $wpdb;
        $exists = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}barber_companies WHERE id = 1" );
        if ( ! $exists ) {
            $wpdb->insert( "{$wpdb->prefix}barber_companies", [
                'name'       => get_bloginfo( 'name' ),
                'slug'       => 'default',
                'email'      => get_option( 'admin_email' ),
                'plan'       => 'free',
                'status'     => 'active',
                'created_at' => current_time( 'mysql' ),
            ] );
        }
        // Settings padrão
        $defaults = [
            'whatsapp_number'             => '',
            'whatsapp_provider'           => 'cloud_api',
            'booking_min_advance_minutes' => 60,
            'booking_max_advance_days'    => 30,
            'cancellation_hours'          => 2,
            'require_deposit'             => '0',
            'deposit_pct'                 => '50',
            'msg_confirmation'            => 'Olá {nome}! Seu agendamento foi confirmado para {data} às {hora} com {profissional}. Código: {codigo}',
            'msg_reminder'                => 'Olá {nome}! Lembrete: você tem um agendamento em 1h com {profissional}.',
            'msg_review'                  => 'Olá {nome}! Como foi seu atendimento? Avalie: {link}',
            'loyalty_points_per_booking'  => '10',
            'dark_mode'                   => '0',
            'company_regime'              => 'simples', // simples | lucro_presumido | lucro_real
        ];
        foreach ( $defaults as $k => $v ) {
            // Só insere se ainda não existir — nunca sobrescreve configuração salva pelo usuário
            if ( BarberPro_Database::get_setting( $k, "\x00__NOT_SET__\x00" ) === "\x00__NOT_SET__\x00" ) {
                BarberPro_Database::update_setting( $k, $v );
            }
        }
    }

    // ── Plano de contas padrão ─────────────────────────────────────────────────
    public static function seed_expense_categories(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $cid   = 1;

        // Evita duplicar se já existir
        $exists = $wpdb->get_var( "SELECT COUNT(*) FROM {$p}barber_finance_categories WHERE company_id = {$cid} AND is_system = 1" );
        if ( $exists > 0 ) return;

        $now = current_time( 'mysql' );

        $cats = [
            // RECEITAS
            ['receita', 'Serviços',           'REC-001', '#10b981', 'scissors',     1, 1],
            ['receita', 'Venda de Produtos',  'REC-002', '#3b82f6', 'shopping-bag', 1, 2],
            ['receita', 'Mensalidade / Plano','REC-003', '#8b5cf6', 'credit-card',  1, 3],
            ['receita', 'Outras Receitas',    'REC-099', '#6b7280', 'plus-circle',  1, 4],

            // DESPESAS – Pessoal
            ['despesa', 'Comissões',          'DESP-001', '#ef4444', 'users',        1, 10],
            ['despesa', 'Salários / Pró-labore','DESP-002','#f97316','user',         1, 11],
            ['despesa', 'INSS / FGTS',        'DESP-003', '#f97316', 'shield',       1, 12],
            ['despesa', 'Vale Transporte',    'DESP-004', '#f97316', 'truck',        1, 13],
            ['despesa', 'Vale Refeição',      'DESP-005', '#f97316', 'coffee',       1, 14],

            // DESPESAS – Operacional
            ['despesa', 'Aluguel',            'DESP-010', '#dc2626', 'home',         1, 20],
            ['despesa', 'Energia Elétrica',   'DESP-011', '#dc2626', 'zap',          1, 21],
            ['despesa', 'Água / Gás',         'DESP-012', '#dc2626', 'droplet',      1, 22],
            ['despesa', 'Internet / Telefone','DESP-013', '#dc2626', 'wifi',         1, 23],
            ['despesa', 'Limpeza / Higiene',  'DESP-014', '#dc2626', 'trash-2',      1, 24],

            // DESPESAS – Produtos / Estoque
            ['despesa', 'Compra de Produtos', 'DESP-020', '#b45309', 'package',      1, 30],
            ['despesa', 'Materiais / Insumos','DESP-021', '#b45309', 'tool',         1, 31],
            ['despesa', 'Equipamentos',       'DESP-022', '#b45309', 'monitor',      1, 32],

            // DESPESAS – Administrativo
            ['despesa', 'Contador / Assessoria','DESP-030','#7c3aed','briefcase',    1, 40],
            ['despesa', 'Software / Sistemas','DESP-031', '#7c3aed', 'cpu',          1, 41],
            ['despesa', 'Publicidade / Marketing','DESP-032','#7c3aed','trending-up',1, 42],
            ['despesa', 'Taxas / Impostos',   'DESP-033', '#7c3aed', 'percent',      1, 43],
            ['despesa', 'Manutenção / Reparos','DESP-034','#7c3aed', 'settings',     1, 44],
            ['despesa', 'Seguros',            'DESP-035', '#7c3aed', 'umbrella',     1, 45],
            ['despesa', 'Cartório / Jurídico','DESP-036', '#7c3aed', 'file-text',    1, 46],

            // DESPESAS – Financeiro
            ['despesa', 'Tarifas Bancárias',  'DESP-040', '#1d4ed8', 'credit-card',  1, 50],
            ['despesa', 'Juros / IOF',        'DESP-041', '#1d4ed8', 'alert-circle', 1, 51],
            ['despesa', 'Parcelamentos',      'DESP-042', '#1d4ed8', 'layers',       1, 52],

            // DESPESAS – Outros
            ['despesa', 'Outras Despesas',    'DESP-099', '#6b7280', 'more-horizontal',1,60],
        ];

        foreach ( $cats as $c ) {
            $wpdb->insert( "{$p}barber_finance_categories", [
                'company_id' => $cid,
                'type'       => $c[0],
                'name'       => $c[1],
                'code'       => $c[2],
                'color'      => $c[3],
                'icon'       => $c[4],
                'is_system'  => $c[5],
                'sort_order' => $c[6],
                'status'     => 'active',
                'created_at' => $now,
            ] );
        }
    }

    /**
     * Insere variantes padrão de tamanho para lava-car (apenas como exemplo/referência).
     * O usuário pode editar/excluir conforme necessário.
     */
    public static function seed_vehicle_variants(): void {
        // Não insere automaticamente — variantes são criadas pelo admin por serviço.
        // Este método existe para futuras seeds opcionais.
    }

    /**
     * Migração v3.1.1 → adiciona colunas novas se não existirem (sites existentes).
     */
    public static function maybe_migrate(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // ── barber_products: colunas da loja (photo, weight_g, shop_active) ──
        $prod_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$p}barber_products" );
        if ( ! in_array('photo',       $prod_cols, true) )
            $wpdb->query( "ALTER TABLE {$p}barber_products ADD COLUMN photo VARCHAR(500) DEFAULT NULL AFTER barcode" );
        if ( ! in_array('weight_g',    $prod_cols, true) )
            $wpdb->query( "ALTER TABLE {$p}barber_products ADD COLUMN weight_g INT NOT NULL DEFAULT 0 AFTER photo" );
        if ( ! in_array('shop_active', $prod_cols, true) )
            $wpdb->query( "ALTER TABLE {$p}barber_products ADD COLUMN shop_active TINYINT(1) NOT NULL DEFAULT 0 AFTER weight_g" );

        // ── barber_message_queue: fila de mensagens WhatsApp ────────────────────
        if ( class_exists('BarberPro_Message_Queue') ) {
            BarberPro_Message_Queue::install();
        }

        // ── barber_clients: carteira de clientes (DB v3.0 — recorrência / movimento) ──
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}barber_clients (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id      INT UNSIGNED NOT NULL DEFAULT 1,
            name            VARCHAR(150) NOT NULL,
            phone           VARCHAR(30)  NOT NULL,
            email           VARCHAR(150) DEFAULT NULL,
            tipo            ENUM('normal','vip','recorrente') NOT NULL DEFAULT 'normal',
            recorrencia_dias INT UNSIGNED DEFAULT NULL,
            professional_id INT UNSIGNED DEFAULT NULL,
            notes           TEXT DEFAULT NULL,
            total_visits    INT UNSIGNED NOT NULL DEFAULT 0,
            last_visit      DATE DEFAULT NULL,
            next_reminder   DATE DEFAULT NULL,
            wp_user_id      BIGINT UNSIGNED DEFAULT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY phone_company (phone, company_id),
            KEY company_id (company_id),
            KEY tipo (tipo),
            KEY professional_id (professional_id),
            KEY wp_user_id (wp_user_id)
        ) " . $wpdb->get_charset_collate() . ";" );

        // wp_user_id em barber_clients (instâncias existentes)
        $cols_clients = $wpdb->get_col( "SHOW COLUMNS FROM {$p}barber_clients" );
        if ( ! in_array( 'wp_user_id', $cols_clients, true ) ) {
            $wpdb->query( "ALTER TABLE {$p}barber_clients ADD COLUMN wp_user_id BIGINT UNSIGNED DEFAULT NULL AFTER next_reminder" );
        }
        if ( ! in_array( 'recurrence_weekdays', $cols_clients, true ) ) {
            $wpdb->query( "ALTER TABLE {$p}barber_clients ADD COLUMN recurrence_weekdays VARCHAR(32) DEFAULT NULL AFTER recorrencia_dias" );
        }
        if ( ! in_array( 'last_absence_sent', $cols_clients, true ) ) {
            $wpdb->query( "ALTER TABLE {$p}barber_clients ADD COLUMN last_absence_sent DATE DEFAULT NULL AFTER recurrence_weekdays" );
        }

        // client_slot_interval e avg_return_days em professionals
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$p}barber_professionals" );
        if ( ! in_array( 'client_slot_interval', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$p}barber_professionals ADD COLUMN client_slot_interval INT NOT NULL DEFAULT 60 AFTER slot_interval" );
        }
        if ( ! in_array( 'avg_return_days', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$p}barber_professionals ADD COLUMN avg_return_days INT NOT NULL DEFAULT 0 AFTER client_slot_interval" );
        }
        // Ajusta profissionais com valores inválidos de versões anteriores
        $wpdb->query( "UPDATE {$p}barber_professionals SET slot_interval = 15 WHERE slot_interval <= 0 OR slot_interval = 30" );
        $wpdb->query( "UPDATE {$p}barber_professionals SET client_slot_interval = 60 WHERE client_slot_interval IS NULL OR client_slot_interval <= 0" );
    }

}