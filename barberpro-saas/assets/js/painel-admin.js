/* =========================================================================
   BarberPro – Painel Admin Frontend JS
   ========================================================================= */
(function($) {
    'use strict';

    // ── Kanban Drag & Drop ────────────────────────────────────────────────
    var dragging = null;

    $(document).on('dragstart', '.bpa-kanban-card', function(e) {
        dragging = this;
        $(this).addClass('dragging');
        e.originalEvent.dataTransfer.effectAllowed = 'move';
        e.originalEvent.dataTransfer.setData('text/plain', $(this).data('id'));
    });

    $(document).on('dragend', '.bpa-kanban-card', function() {
        $(this).removeClass('dragging');
        dragging = null;
    });

    $(document).on('dragover', '.bpa-kanban-cards', function(e) {
        e.preventDefault();
        e.originalEvent.dataTransfer.dropEffect = 'move';
        $(this).closest('.bpa-kanban-col').addClass('drag-over');
    });

    $(document).on('dragleave', '.bpa-kanban-col', function(e) {
        if (!$(this).is($(e.relatedTarget).closest('.bpa-kanban-col'))) {
            $(this).removeClass('drag-over');
        }
    });

    $(document).on('drop', '.bpa-kanban-cards', function(e) {
        e.preventDefault();
        var $col    = $(this).closest('.bpa-kanban-col');
        var newStatus = $col.data('status');
        $col.removeClass('drag-over');

        if (!dragging) return;

        var $card     = $(dragging);
        var bookingId = $card.data('id');
        var oldStatus = $card.closest('.bpa-kanban-col').data('status');

        if (newStatus === oldStatus) return;

        // Move visualmente
        $(this).append($card);

        // Atualiza contadores
        $col.find('.bpa-kanban-header .bpa-kanban-count').text(
            parseInt($col.find('.bpa-kanban-count').text() || 0) + 1
        );
        var $oldCol = $('.bpa-kanban-col[data-status="' + oldStatus + '"]');
        $oldCol.find('.bpa-kanban-count').text(
            Math.max(0, parseInt($oldCol.find('.bpa-kanban-count').text() || 1) - 1)
        );

        // Atualiza via AJAX
        var ajaxUrl = $('#bpaKanban').data('ajaxurl');
        var nonce   = $('#bpaKanban').data('nonce');

        $.post(ajaxUrl, {
            action:     'barberpro_update_booking_status',
            booking_id: bookingId,
            status:     newStatus,
            nonce:      nonce
        }).fail(function() {
            // Reverte visualmente em caso de erro
            $('.bpa-kanban-col[data-status="' + oldStatus + '"] .bpa-kanban-cards').append($card);
            alert('Erro ao atualizar status. Tente novamente.');
        });
    });

    // ── Chart: Receita vs Despesa 12 meses ───────────────────────────────
    if (typeof Chart !== 'undefined' && document.getElementById('bpaChart12m') && window.bpaChart12mData) {
        var d = window.bpaChart12mData;
        new Chart(document.getElementById('bpaChart12m'), {
            type: 'bar',
            data: {
                labels: d.map(function(r){ return r.mes; }),
                datasets: [
                    {
                        label: 'Receita',
                        data: d.map(function(r){ return parseFloat(r.receita); }),
                        backgroundColor: 'rgba(16,185,129,.75)',
                        borderRadius: 4
                    },
                    {
                        label: 'Despesa',
                        data: d.map(function(r){ return parseFloat(r.despesa); }),
                        backgroundColor: 'rgba(239,68,68,.65)',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'top' } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: function(v){ return 'R$ ' + v.toLocaleString('pt-BR'); } }
                    }
                }
            }
        });
    }

})(jQuery);
