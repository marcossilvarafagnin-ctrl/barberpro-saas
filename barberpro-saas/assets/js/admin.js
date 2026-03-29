/**
 * BarberPro SaaS – Admin JavaScript
 * Kanban drag & drop, Chart.js, modais, AJAX
 */
(function ($) {
    'use strict';

    // =========================================================================
    // Chart.js – Gráfico de Receita Mensal
    // =========================================================================
    function initChart() {
        if (typeof Chart === 'undefined' || !document.getElementById('barberproChart')) return;
        if (typeof barberproChartData === 'undefined' || !barberproChartData.length) return;

        const labels   = barberproChartData.map(r => r.month);
        const revenues = barberproChartData.map(r => parseFloat(r.revenue));
        const expenses = barberproChartData.map(r => parseFloat(r.expenses));

        new Chart(document.getElementById('barberproChart'), {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'Receita', data: revenues, backgroundColor: 'rgba(16,185,129,.7)', borderRadius: 6 },
                    { label: 'Despesas', data: expenses, backgroundColor: 'rgba(239,68,68,.6)', borderRadius: 6 },
                ],
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'top' } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'R$ ' + v } } },
            },
        });
    }

    // =========================================================================
    // Kanban Drag & Drop
    // =========================================================================
    function initKanban() {
        if (!$('#barberproKanban').length) return;

        let dragCard = null;

        $(document).on('dragstart', '.kanban-card', function (e) {
            dragCard = this;
            $(this).addClass('dragging');
            e.originalEvent.dataTransfer.effectAllowed = 'move';
        });
        $(document).on('dragend', '.kanban-card', function () {
            $(this).removeClass('dragging');
            dragCard = null;
            $('.kanban-column').removeClass('drag-over');
        });
        $(document).on('dragover', '.kanban-column', function (e) {
            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = 'move';
            $('.kanban-column').removeClass('drag-over');
            $(this).addClass('drag-over');
        });
        $(document).on('dragleave', '.kanban-column', function () {
            $(this).removeClass('drag-over');
        });
        $(document).on('drop', '.kanban-column', function (e) {
            e.preventDefault();
            $(this).removeClass('drag-over');
            if (!dragCard) return;

            const newStatus = $(this).data('status');
            const cardId    = parseInt($(dragCard).data('id'));
            const cardsWrap = $(this).find('.kanban-cards');

            cardsWrap.append(dragCard);

            // Update column counts
            updateColumnCounts();

            // AJAX – atualiza status
            $.ajax({
                url:  barberproAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action:     'barberpro_update_booking_status',
                    nonce:      barberproAdmin.ajaxNonce,
                    booking_id: cardId,
                    status:     newStatus,
                },
                error: () => { alert(barberproAdmin.i18n.error); },
            });
        });
    }

    function updateColumnCounts() {
        $('.kanban-column').each(function () {
            const count = $(this).find('.kanban-card').length;
            $(this).find('.kanban-count').text(count);
        });
    }

    // =========================================================================
    // Booking Status Select (lista de agendamentos)
    // =========================================================================
    function initStatusSelects() {
        $(document).on('change', '.booking-status-select', function () {
            const id     = $(this).data('id');
            const status = $(this).val();
            $.ajax({
                url:  barberproAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'barberpro_update_booking_status', nonce: barberproAdmin.ajaxNonce, booking_id: id, status },
                success: (res) => {
                    if (res.success) {
                        const row   = $(this).closest('tr');
                        const badge = row.find('.barberpro-badge');
                        badge.attr('class', 'barberpro-badge status-' + status).text(status);
                    } else { alert(res.data.message || barberproAdmin.i18n.error); }
                },
            });
        });
    }

    // =========================================================================
    // Service Modal
    // =========================================================================
    function initServiceModal() {
        if (!$('#serviceModal').length) return;

        // Open
        $('#btnAddService').on('click', () => {
            $('#serviceId').val(0);
            $('#serviceModalTitle').text('Novo Serviço');
            $('#serviceForm')[0].reset();
            $('#serviceModal').show();
        });

        // Edit
        $(document).on('click', '.btn-edit-service', function () {
            const btn = $(this);
            $('#serviceId').val(btn.data('id'));
            $('#serviceName').val(btn.data('name'));
            $('#servicePrice').val(btn.data('price'));
            $('#serviceDuration').val(btn.data('duration'));
            $('#serviceCategory').val(btn.data('category'));
            $('#serviceDesc').val(btn.data('description'));
            $('#serviceModalTitle').text('Editar Serviço');
            $('#serviceModal').show();
        });

        // Close
        $('.barberpro-modal-close, #serviceModal').on('click', function (e) {
            if (e.target === this) $('#serviceModal').hide();
        });

        // Submit
        $('#serviceForm').on('submit', function (e) {
            e.preventDefault();
            const btn = $(this).find('[type=submit]');
            btn.text(barberproAdmin.i18n.saving).prop('disabled', true);
            const formData = $(this).serialize() + '&action=barberpro_save_service';
            $.post(barberproAdmin.ajaxUrl, formData, (res) => {
                btn.prop('disabled', false).text('Salvar');
                if (res.success) { alert(barberproAdmin.i18n.saved); location.reload(); }
                else { alert(res.data.message || barberproAdmin.i18n.error); }
            });
        });

        // Delete
        $(document).on('click', '.btn-delete-service', function () {
            if (!confirm(barberproAdmin.i18n.confirm_delete)) return;
            const id = $(this).data('id');
            $.post(barberproAdmin.ajaxUrl, { action:'barberpro_save_service', nonce:barberproAdmin.ajaxNonce, id, status:'inactive' }, () => location.reload());
        });
    }

    // =========================================================================
    // Professional Modal
    // =========================================================================
    function initProModal() {
        if (!$('#proModal').length) return;

        $('#btnAddPro').on('click', () => { $('#proForm')[0].reset(); $('#proModal').show(); });
        $('.barberpro-modal-close, #proModal').on('click', function (e) {
            if (e.target === this) $('#proModal').hide();
        });

        $('#proForm').on('submit', function (e) {
            e.preventDefault();
            const btn = $(this).find('[type=submit]');
            btn.text(barberproAdmin.i18n.saving).prop('disabled', true);

            // Monta work_days como string
            const days = [];
            $('input[name="work_days[]"]:checked').each(function () { days.push($(this).val()); });
            const formData = $(this).serialize() + '&action=barberpro_save_professional&work_days=' + days.join(',');
            $.post(barberproAdmin.ajaxUrl, formData, (res) => {
                btn.prop('disabled', false).text('Salvar');
                if (res.success) { alert(barberproAdmin.i18n.saved); location.reload(); }
                else { alert(res.data.message || barberproAdmin.i18n.error); }
            });
        });
    }

    // =========================================================================
    // Settings Tabs
    // =========================================================================
    function initSettingsTabs() {
        if (!$('.nav-tab-wrapper').length) return;
        $('.nav-tab').on('click', function (e) {
            e.preventDefault();
            const target = $(this).attr('href');
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.barberpro-tab-content').hide();
            $(target).show();
        });
    }

    // =========================================================================
    // Init
    // =========================================================================
    $(document).ready(function () {
        initChart();
        initKanban();
        initStatusSelects();
        initServiceModal();
        initProModal();
        initSettingsTabs();
    });

})(jQuery);

// =========================================================================
// Finance Charts
// =========================================================================
function initFinanceCharts() {
    // Chart: Receita vs Despesa 12 meses
    if (typeof Chart !== 'undefined' && document.getElementById('chartReceita') && window.bpChart12m) {
        var d = window.bpChart12m;
        new Chart(document.getElementById('chartReceita'), {
            type: 'bar',
            data: {
                labels: d.map(r => r.mes),
                datasets: [
                    { label: 'Receita',  data: d.map(r => parseFloat(r.receita)),  backgroundColor: 'rgba(16,185,129,.75)', borderRadius: 4 },
                    { label: 'Despesa',  data: d.map(r => parseFloat(r.despesa)),  backgroundColor: 'rgba(239,68,68,.65)', borderRadius: 4 },
                ]
            },
            options: { responsive: true, plugins: { legend: { position: 'top' } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'R$ ' + v.toLocaleString('pt-BR') } } }
            }
        });
    }

    // Chart: Método de pagamento (doughnut)
    if (typeof Chart !== 'undefined' && document.getElementById('chartMethod') && window.bpByMethod) {
        var m = window.bpByMethod;
        new Chart(document.getElementById('chartMethod'), {
            type: 'doughnut',
            data: {
                labels: m.map(r => r.payment_method),
                datasets: [{ data: m.map(r => parseFloat(r.total)), backgroundColor: ['#10b981','#3b82f6','#8b5cf6','#f59e0b','#ef4444','#06b6d4','#84cc16'] }]
            },
            options: { responsive: true, plugins: { legend: { position: 'right' } } }
        });
    }

    // Chart: Top expenses (bar horizontal)
    if (typeof Chart !== 'undefined' && document.getElementById('chartExpenses') && window.bpTopExpenses) {
        var e = window.bpTopExpenses;
        new Chart(document.getElementById('chartExpenses'), {
            type: 'bar',
            data: {
                labels: e.map(r => r.name),
                datasets: [{ label: 'Despesa', data: e.map(r => parseFloat(r.total)), backgroundColor: e.map(r => r.color || '#ef4444'), borderRadius: 4 }]
            },
            options: {
                indexAxis: 'y', responsive: true, plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { callback: v => 'R$ ' + v.toLocaleString('pt-BR') } } }
            }
        });
    }

    // Chart: Fluxo de caixa (line)
    if (typeof Chart !== 'undefined' && document.getElementById('chartCashFlow') && window.bpCashFlow) {
        var cf = window.bpCashFlow;
        new Chart(document.getElementById('chartCashFlow'), {
            type: 'line',
            data: {
                labels: cf.map(r => r.date),
                datasets: [
                    { label: 'Entradas', data: cf.map(r => r.receita), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.1)', fill: true, tension: 0.3 },
                    { label: 'Saídas',   data: cf.map(r => r.despesa), borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,.08)',  fill: true, tension: 0.3 },
                    { label: 'Saldo Acumulado', data: cf.map(r => r.saldo_acum), borderColor: '#3b82f6', borderWidth: 2, fill: false, tension: 0.3 },
                ]
            },
            options: { responsive: true, plugins: { legend: { position: 'top' } },
                scales: { y: { ticks: { callback: v => 'R$ ' + v.toLocaleString('pt-BR') } } }
            }
        });
    }
}

// Categoria filter: filtra opções do select de categoria por tipo
function initFinanceCategoryFilter() {
    var typeEl = document.getElementById('bpFinType');
    var catEl  = document.getElementById('bpFinCat');
    if (!typeEl || !catEl) return;

    function filterCats() {
        var type = typeEl.value;
        Array.from(catEl.options).forEach(function(opt) {
            if (!opt.value) return; // placeholder
            var optType = opt.getAttribute('data-type');
            opt.hidden = optType && optType !== type;
        });
        // Show/hide optgroups
        Array.from(catEl.querySelectorAll('optgroup')).forEach(function(og) {
            var isRecGroup  = og.label.includes('RECEITA');
            var isDespGroup = og.label.includes('DESPESA');
            if (isRecGroup)  og.hidden = type !== 'receita';
            if (isDespGroup) og.hidden = type !== 'despesa';
        });
    }
    typeEl.addEventListener('change', filterCats);
    filterCats(); // on load
}

$(document).ready(function () {
    initFinanceCharts();
    initFinanceCategoryFilter();
});
