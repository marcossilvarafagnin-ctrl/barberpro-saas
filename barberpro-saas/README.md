# BarberPro SaaS Manager

Plugin WordPress profissional de gestão para barbearias, salões e clínicas de estética.

---

## Instalação

1. Faça o upload da pasta `barberpro-saas/` para `/wp-content/plugins/`
2. Ative o plugin no painel WordPress → Plugins
3. Todas as tabelas e configurações são criadas automaticamente na ativação
4. Crie uma página e adicione o shortcode `[barberpro_agendamento]`
5. Acesse **BarberPro** no menu do painel para começar a configurar

---

## Shortcodes

| Shortcode | Descrição |
|---|---|
| `[barberpro_agendamento]` | Página pública de agendamento (wizard 7 etapas) |
| `[barberpro_painel_cliente]` | Painel do cliente logado |

---

## Estrutura de Pastas

```
barberpro-saas/
├── barberpro-saas.php         ← Plugin principal (singleton)
├── uninstall.php              ← Limpeza ao desinstalar
├── includes/
│   ├── class-database.php     ← Abstração DB + prepared statements
│   ├── class-installer.php    ← Criação de tabelas (dbDelta) + defaults
│   ├── class-roles.php        ← 3 roles personalizadas + capabilities
│   ├── class-api.php          ← REST API endpoints (/wp-json/barberpro/v1/)
│   ├── class-whatsapp.php     ← Cloud API / Twilio / Z-API + WP-Cron
│   ├── class-finance.php      ← Dashboard financeiro + exportação CSV
│   ├── class-services.php     ← CRUD de serviços
│   ├── class-professionals.php← CRUD de profissionais
│   └── class-bookings.php     ← Lógica de negócio, slots, validações
├── admin/
│   ├── class-admin-menu.php   ← Menu WP-Admin + AJAX handlers
│   └── views/
│       ├── dashboard.php      ← KPIs + gráfico + agenda do dia
│       ├── kanban.php         ← Drag & Drop em tempo real
│       ├── bookings.php       ← Lista filtrável
│       ├── professionals.php  ← Grid de profissionais
│       ├── services.php       ← CRUD com modal
│       ├── finance.php        ← Relatórios + CSV
│       └── settings.php       ← Config em abas (WhatsApp, mensagens, etc.)
├── public/
│   ├── class-frontend.php     ← Shortcodes + AJAX frontend
│   └── templates/
│       ├── agendamento.php    ← Wizard 7 etapas
│       └── painel-cliente.php ← Área do cliente
└── assets/
    ├── css/admin.css          ← Kanban, KPIs, modais
    ├── css/public.css         ← Wizard, cards, responsivo
    ├── js/admin.js            ← Chart.js, drag&drop, modais
    └── js/public.js           ← Wizard AJAX, slots, booking
```

---

## Banco de Dados (7 tabelas)

| Tabela | Conteúdo |
|---|---|
| `wp_barber_companies` | Empresas – base para SaaS multiempresa |
| `wp_barber_professionals` | Profissionais com horários e comissão |
| `wp_barber_services` | Serviços com preço e duração |
| `wp_barber_bookings` | Agendamentos com código único |
| `wp_barber_finance` | Receitas e despesas |
| `wp_barber_commissions` | Comissões por atendimento |
| `wp_barber_settings` | Configurações por empresa |

---

## REST API Endpoints

```
GET  /wp-json/barberpro/v1/services
POST /wp-json/barberpro/v1/services
GET  /wp-json/barberpro/v1/services/{id}
PUT  /wp-json/barberpro/v1/services/{id}
DELETE /wp-json/barberpro/v1/services/{id}

GET  /wp-json/barberpro/v1/professionals
GET  /wp-json/barberpro/v1/slots?professional_id=&date=&service_id=

POST /wp-json/barberpro/v1/bookings
GET  /wp-json/barberpro/v1/kanban
PATCH /wp-json/barberpro/v1/bookings/{id}/status

GET  /wp-json/barberpro/v1/finance/summary
```

---

## Roles

| Role | Capabilities principais |
|---|---|
| `barber_admin` | Gestão total: serviços, staff, financeiro, configurações |
| `barber_professional` | Agenda própria, status, bloqueio de horários, comissão |
| `barber_client` | Agendamento, cancelamento, avaliação |

---

## WhatsApp Providers

Configure em **BarberPro → Configurações → WhatsApp**:

- **WhatsApp Cloud API** (Meta) – requer token + Phone Number ID
- **Twilio** – requer Account SID + Auth Token + número
- **Z-API** – requer Instance + Token

Mensagens editáveis com variáveis: `{nome}`, `{data}`, `{hora}`, `{profissional}`, `{servico}`, `{codigo}`, `{link}`

---

## Preparação para SaaS

- Todas as tabelas possuem `company_id`
- Filtro `barberpro_company_id` permite sobrescrever o tenant atual
- Hook `barberpro_booking_created` permite integração com sistemas externos
- Hook `barberpro_booking_status_changed` para automações personalizadas
- REST API com autenticação nonce/role

---

## Dependências

- **WordPress** 6.0+
- **PHP** 8.0+
- **jQuery** (incluído no WP)
- **WooCommerce** (opcional, para pagamentos online)
- **Chart.js** (carregado via CDN no admin)
