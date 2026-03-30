/* BarberPro App – Frontend SPA Controller */
(function(){
'use strict';

const BP = {
  ajaxUrl: bpAppData.ajaxUrl,
  nonce:   bpAppData.nonce,
  restUrl: bpAppData.restUrl,
  user:    bpAppData.user || {},
  modules: bpAppData.modules || {},
  currentSection: null,
  currentTab: null,

  // ── Init ──────────────────────────────────────────────────────
  init() {
    if (!this.user.logged_in) {
      this.showLogin();
    } else {
      this.showApp();
      const saved = sessionStorage.getItem('bp_section') || 'dashboard';
      this.navigate(saved);
    }
    this.bindEvents();
  },

  // ── Login ─────────────────────────────────────────────────────
  showLogin() {
    document.getElementById('bpLogin').style.display = 'flex';
    document.getElementById('bpApp').style.display   = 'none';
  },

  showApp() {
    document.getElementById('bpLogin').style.display = 'none';
    document.getElementById('bpApp').style.display   = 'flex';
    this.buildSidebar();
    this.buildBottomNav();
    if (this.user.name) {
      document.querySelectorAll('.bp-user-name').forEach(el => el.textContent = this.user.name);
      document.querySelectorAll('.bp-user-avatar').forEach(el => el.textContent = this.user.name.charAt(0).toUpperCase());
      document.querySelectorAll('.bp-user-role').forEach(el => el.textContent = this.user.role_label || 'Usuário');
    }
  },

  // ── Login form ────────────────────────────────────────────────
  submitLogin(form) {
    const btn = form.querySelector('[type=submit]');
    const err = document.getElementById('bpLoginError');
    btn.disabled = true;
    btn.textContent = 'Entrando...';
    err.classList.remove('show');

    const data = new FormData();
    data.append('action', 'bp_app_login');
    data.append('nonce',  this.nonce);
    data.append('username', form.username.value.trim());
    data.append('password', form.password.value);
    data.append('panel_mode', (typeof bpAppData !== 'undefined' && bpAppData.panelMode) ? bpAppData.panelMode : 'full');

    // Se nonce vier de cache pode estar expirado — tenta de qualquer forma
    // o servidor aceita login sem nonce estrito
    fetch(this.ajaxUrl, { method:'POST', body:data, credentials:'same-origin' })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          this.user = res.data.user;
          window.bpAppData.user = this.user;
          if (res.data.modules && typeof res.data.modules === 'object') {
            this.modules = res.data.modules;
            window.bpAppData.modules = res.data.modules;
          }
          if (res.data.nonce) this.nonce = res.data.nonce;
          this.showApp();
          this.buildSidebar();
          this.buildBottomNav();
          this.navigate(typeof bpAppData.startSection === 'string' ? bpAppData.startSection : 'dashboard');
        } else {
          err.textContent = res.data?.message || 'Usuário ou senha incorretos.';
          err.classList.add('show');
          btn.disabled = false;
          btn.textContent = 'Entrar';
        }
      })
      .catch(() => {
        err.textContent = 'Erro de conexão. Tente novamente.';
        err.classList.add('show');
        btn.disabled = false;
        btn.textContent = 'Entrar';
      });
  },

  // ── Logout ───────────────────────────────────────────────────
  logout() {
    const data = new FormData();
    data.append('action', 'bp_app_logout');
    data.append('nonce',  this.nonce);
    fetch(this.ajaxUrl, { method:'POST', body:data, credentials:'same-origin' })
      .then(() => {
        sessionStorage.removeItem('bp_section');
        this.user = {};
        this.showLogin();
      });
  },

  // ── Navigation ────────────────────────────────────────────────
  navigate(section, tab, params) {
    this.currentSection = section;
    sessionStorage.setItem('bp_section', section);

    // Update nav active states
    document.querySelectorAll('.bp-nav-item').forEach(el => {
      el.classList.toggle('active', el.dataset.section === section);
    });
    document.querySelectorAll('.bp-bottom-item').forEach(el => {
      el.classList.toggle('active', el.dataset.section === section);
    });

    // Update topbar title
    const item = document.querySelector(`.bp-nav-item[data-section="${section}"]`);
    const title = item ? item.querySelector('.bp-nav-label')?.textContent : 'BarberPro';
    document.querySelectorAll('.bp-topbar-title').forEach(el => el.textContent = title);

    // Close sidebar on mobile
    if (window.innerWidth <= 1024) this.closeSidebar();

    this.loadSection(section, tab, params);
  },

  // ── Load section via AJAX ─────────────────────────────────────
  loadSection(section, tab, params) {
    const content = document.getElementById('bpContent');
    content.innerHTML = `<div class="bp-loading"><div class="bp-spinner"></div><span>Carregando...</span></div>`;

    const data = new FormData();
    data.append('action',  'bp_app_section');
    data.append('nonce',   this.nonce);
    data.append('section', section);
    if (tab) data.append('tab', tab);
    // extra params (ex: {date:'2026-03-12'} para agenda)
    if (params && typeof params === 'object') {
      Object.entries(params).forEach(([k,v]) => data.append(k, v));
    }

    fetch(this.ajaxUrl, { method:'POST', body:data, credentials:'same-origin' })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          content.innerHTML = res.data.html;
          content.querySelectorAll('script').forEach(s => {
            const ns = document.createElement('script');
            ns.textContent = s.textContent;
            s.replaceWith(ns);
          });
          // Animate in
          content.querySelectorAll('.bp-animate-in').forEach((el,i) => {
            el.style.animationDelay = (i * 0.05) + 's';
          });
        } else {
          const errMsg = res.data?.message || JSON.stringify(res.data) || 'Resposta inválida do servidor';
          console.error('[BP] section error:', section, errMsg, res);
          content.innerHTML = `<div class="bp-card" style="padding:24px;text-align:center;max-width:500px;margin:40px auto">
            <div style="font-size:2rem;margin-bottom:8px">⚠️</div>
            <div style="font-weight:700;margin-bottom:8px">Erro ao carregar: ${section}</div>
            <div style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:10px;font-size:.82rem;color:var(--red);font-family:var(--font-mono);text-align:left;margin-bottom:14px;word-break:break-all">${errMsg}</div>
            <button class="bp-btn bp-btn-primary" onclick="BP.navigate('${section}','${tab}')">🔄 Tentar novamente</button>
          </div>`;
        }
      })
      .catch(() => {
        content.innerHTML = `<div class="bp-empty"><div class="bp-empty-icon">📡</div><div class="bp-empty-title">Sem conexão</div><p class="bp-empty-text">Verifique sua internet.</p></div>`;
      });
  },

  // ── Sidebar ───────────────────────────────────────────────────
  buildSidebar() {
    const nav = document.getElementById('bpSidebarNav');
    if (!nav) return;
    nav.innerHTML = this.buildNavHTML();
  },

  buildNavHTML() {
    const sections = this.getNavSections();
    let html = '';
    sections.forEach(group => {
      html += `<div class="bp-nav-section"><div class="bp-nav-section-title">${group.title}</div>`;
      group.items.forEach(item => {
        if (item.disabled) return;
        html += `<button class="bp-nav-item" data-section="${item.id}" onclick="BP.navigate('${item.id}')">
          <span class="bp-nav-icon">${item.icon}</span>
          <span class="bp-nav-label">${item.label}</span>
        </button>`;
      });
      html += `</div>`;
    });
    return html;
  },

  buildBottomNav() {
    const nav = document.getElementById('bpBottomNavInner');
    if (!nav) return;
    const bottomItems = this.getBottomNavItems();
    nav.innerHTML = bottomItems.map(item => `
      <button class="bp-bottom-item" data-section="${item.id}" onclick="BP.navigate('${item.id}')">
        <span class="bp-bottom-item-icon">${item.icon}</span>
        <span class="bp-bottom-item-label">${item.short}</span>
      </button>
    `).join('');
  },

  getNavSections() {
    const mods  = this.modules;
    const items = [
      { title: 'Geral', items: [
        { id:'dashboard', icon:'📊', label:'Dashboard' },
        { id:'ganhos',    icon:'💰', label:'Ganhos', },
      ]},
    ];
    if (mods.barbearia) items.push({ title: '✂️ Barbearia', items: [
      { id:'barbearia_agenda',       icon:'📅', label:'Agendamentos'   },
      { id:'barbearia_kanban',       icon:'🗂', label:'Kanban'         },
      { id:'barbearia_servicos',     icon:'✂️', label:'Serviços'       },
      { id:'barbearia_profis',       icon:'👤', label:'Profissionais'  },
      { id:'barbearia_finance',      icon:'💵', label:'Financeiro'     },
      { id:'barbearia_loja_produtos',icon:'🛍️', label:'Loja — Produtos'},
      { id:'barbearia_loja_pedidos', icon:'📦', label:'Loja — Pedidos' },
      { id:'barbearia_clientes',     icon:'👥', label:'Clientes'       },
      { id:'barbearia_mensagens',    icon:'📢', label:'Mensagens WhatsApp' },
    ]});
    if (mods.lavacar) items.push({ title: '🚗 Lava-Car', items: [
      { id:'lavacar_agenda',         icon:'📅', label:'Agendamentos'   },
      { id:'lavacar_kanban',         icon:'🗂', label:'Kanban'         },
      { id:'lavacar_servicos',       icon:'🔧', label:'Serviços'       },
      { id:'lavacar_profis',         icon:'👤', label:'Atendentes'     },
      { id:'lavacar_finance',        icon:'💵', label:'Financeiro'     },
      { id:'lavacar_loja_produtos',  icon:'🛍️', label:'Loja — Produtos'},
      { id:'lavacar_loja_pedidos',   icon:'📦', label:'Loja — Pedidos' },
      { id:'lavacar_clientes',       icon:'👥', label:'Clientes'       },
      { id:'lavacar_mensagens',      icon:'📢', label:'Mensagens WhatsApp' },
    ]});
    if (mods.bar) items.push({ title: '🍺 Bar / Eventos', items: [
      { id:'bar_caixa',    icon:'🏧', label:'Caixa'         },
      { id:'bar_admin',    icon:'📊', label:'Admin Bar'     },
      { id:'bar_comandas', icon:'🧾', label:'Comandas'      },
      { id:'bar_produtos', icon:'📦', label:'Produtos'      },
      { id:'bar_estoque',  icon:'📈', label:'Estoque'       },
      { id:'bar_clientes', icon:'👥', label:'Clientes'       },
      { id:'bar_mensagens',icon:'📢', label:'Mensagens WhatsApp' },
    ]});
    items.push({ title: 'Sistema', items: [
      { id:'licenca',   icon:'🔑', label:'Licença'         },
      { id:'backup',    icon:'💾', label:'Backup'          },
      { id:'settings',  icon:'⚙️', label:'Configurações'  },
    ]});
    return items;
  },

  getBottomNavItems() {
    const mods = this.modules;
    const items = [{ id:'dashboard', icon:'📊', short:'Início' }];
    if (mods.barbearia) items.push({ id:'barbearia_agenda', icon:'✂️', short:'Barber' });
    if (mods.lavacar)   items.push({ id:'lavacar_agenda',   icon:'🚗', short:'Lava-Car' });
    if (mods.bar)       items.push({ id:'bar_caixa',        icon:'🍺', short:'Caixa' });
    items.push({ id:'ganhos', icon:'💰', short:'Ganhos' });
    return items.slice(0, 5);
  },

  // ── Sidebar toggle ────────────────────────────────────────────
  openSidebar() {
    document.getElementById('bpSidebar').classList.add('open');
    document.getElementById('bpSidebarOverlay').classList.add('show');
    document.body.style.overflow = 'hidden';
  },
  closeSidebar() {
    document.getElementById('bpSidebar').classList.remove('open');
    document.getElementById('bpSidebarOverlay').classList.remove('show');
    document.body.style.overflow = '';
  },

  // ── AJAX helper ──────────────────────────────────────────────
  ajax(action, params={}, _retry=false) {
    const data = new FormData();
    data.append('action', action);
    data.append('nonce',  this.nonce);
    Object.entries(params).forEach(([k,v]) => data.append(k, v));
    return fetch(this.ajaxUrl, { method:'POST', credentials:'same-origin', body:data })
      .then(r => r.json())
      .then(res => {
        // Nonce inválido → usa nonce da resposta ou faz refresh, depois re-tenta uma vez
        if (!_retry && !res.success && res.data && (res.data.code === 'invalid_nonce' || /nonce/i.test(res.data.message||''))) {
          if (res.data.nonce) {
            this.nonce = res.data.nonce; // usa nonce fresco da resposta
          }
          return this.refreshNonce().then(() => this.ajax(action, params, true));
        }
        return res;
      })
      .catch(err => {
        console.error('[BP] ajax error:', err);
        return {success:false, data:{message:'Erro de conexão: '+err.message}};
      });
  },

  refreshNonce() {
    return fetch(this.ajaxUrl+'?action=bp_refresh_nonce', {credentials:'same-origin'})
      .then(r => r.json())
      .then(res => { if(res.success && res.data.nonce) this.nonce = res.data.nonce; })
      .catch(()=>{});
  },

  // ── Modal ─────────────────────────────────────────────────────
  openModal(html) {
    let backdrop = document.getElementById('bpModalBackdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.id = 'bpModalBackdrop';
      backdrop.className = 'bp-modal-backdrop';
      backdrop.addEventListener('click', e => { if(e.target===backdrop) this.closeModal(); });
      document.body.appendChild(backdrop);
    }
    backdrop.innerHTML = `<div class="bp-modal">${html}</div>`;
    // Re-executa scripts inline injetados via innerHTML (browser não executa automaticamente)
    backdrop.querySelectorAll('script').forEach(s => {
      const ns = document.createElement('script');
      ns.textContent = s.textContent;
      s.parentNode.replaceChild(ns, s);
    });
    backdrop.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  },
  closeModal() {
    const b = document.getElementById('bpModalBackdrop');
    if (b) { b.style.display = 'none'; b.innerHTML = ''; }
    document.body.style.overflow = '';
  },

  // ── Toast ─────────────────────────────────────────────────────
  toast(msg, type='success') {
    if (typeof msg === 'object') msg = JSON.stringify(msg);
    console.log('[BP] toast:', type, msg);
    let wrap = document.getElementById('bpToastWrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'bpToastWrap';
      wrap.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;max-width:320px;';
      document.body.appendChild(wrap);
    }
    const t = document.createElement('div');
    t.style.cssText = `background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:12px 16px;font-size:.84rem;font-family:var(--font);color:var(--text);display:flex;align-items:center;gap:8px;box-shadow:var(--shadow);animation:bp-fade-up .3s ease;`;
    const icons = { success:'✅', error:'❌', warn:'⚠️', info:'ℹ️' };
    t.innerHTML = `<span>${icons[type]||'ℹ️'}</span><span>${msg}</span>`;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 3500);
  },

  // ── Money format ──────────────────────────────────────────────
  money(v) {
    return 'R$ ' + parseFloat(v||0).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
  },

  // ── Bind events ───────────────────────────────────────────────
  bindEvents() {
    // Login form
    const loginForm = document.getElementById('bpLoginForm');
    if (loginForm) {
      loginForm.addEventListener('submit', e => {
        e.preventDefault();
        this.submitLogin(loginForm);
      });
    }
    // Sidebar overlay
    const overlay = document.getElementById('bpSidebarOverlay');
    if (overlay) overlay.addEventListener('click', () => this.closeSidebar());
    // Keyboard: Escape closes modal/sidebar
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') { this.closeModal(); this.closeSidebar(); }
    });
  },
};

// Public API
window.BP = BP;
window.bpMoney = v => BP.money(v);

document.addEventListener('DOMContentLoaded', () => BP.init());

})();


// ── Funções globais dos modais e seções ─────────────────────────────────────

// ── Funções globais dos modais e seções ──────────────────────────────────────

function bpSavePro(){
    var id=document.getElementById('pf_id').value;
    var cid=document.getElementById('pf_cid').value;
    // Coleta dias selecionados
    var days=[];
    document.querySelectorAll('input[name="pf_days[]"]:checked').forEach(function(cb){days.push(cb.value);});
    if(days.length===0){BP.toast('Selecione ao menos um dia de trabalho','warn');return;}
    BP.ajax('bp_app_action',{
        sub:'save_pro',
        pro_id:id,
        company_id:cid,
        name:document.getElementById('pf_name').value,
        specialty:document.getElementById('pf_spec').value,
        commission_pct:document.getElementById('pf_comm').value,
        phone:document.getElementById('pf_phone').value,
        work_days:days.join(','),
        work_start:document.getElementById('pf_work_start').value,
        work_end:document.getElementById('pf_work_end').value,
        lunch_start:document.getElementById('pf_lunch_start').value,
        lunch_end:document.getElementById('pf_lunch_end').value,
        slot_interval:document.getElementById('pf_slot_interval').value,
        client_slot_interval:document.getElementById('pf_client_slot_interval').value
    }).then(function(r){
        if(r.success){
            BP.closeModal();
            BP.toast('Profissional salvo!');
            var mod=cid==='1'?'barbearia':'lavacar';
            BP.navigate(mod+'_profis');
        }else{
            BP.toast((r.data&&r.data.message)?r.data.message:'Erro ao salvar','error');
        }
    });
}



function bpUpdateStatus(id, status){
            BP.ajax('bp_app_action',{sub:'update_booking_status',booking_id:id,status:status})
              .then(r=>{ if(r.success){BP.toast('Status atualizado!'); BP.navigate(''+section+'');}else{BP.toast(r.data?.message||'Erro','error');}});
        }
        function bpOpenNewBooking(cid){
            BP.ajax('bp_app_action',{sub:'get_new_booking_form',company_id:cid})
              .then(r=>{ if(r.success) BP.openModal(r.data.html); });
        }

function bpKanbanMove(id,status,mod){
            BP.ajax('bp_app_action',{sub:'update_booking_status',booking_id:id,status:status})
              .then(r=>{if(r.success){BP.toast('Movido!');BP.navigate(mod+'_kanban');}else{BP.toast('Erro','error');}});
        }

function bpOpenServiceForm(id,cid){
            BP.ajax('bp_app_action',{sub:'get_service_form',service_id:id,company_id:cid})
              .then(r=>{if(r.success)BP.openModal(r.data.html);});
        }
        function bpToggleStatus(type,id,section){
            BP.ajax('bp_app_action',{sub:'toggle_'+type,id:id})
              .then(function(r){
                if(r.success){
                    var isActive = r.data.new_status==='active';
                    // Tenta os dois formatos de ID usados no HTML
                    var prefixMap = {service:'bpSvcToggle_', pro:'bpProToggle_', product:'bpProdToggle_'};
                    var prefix = prefixMap[type] || ('bp'+type+'Toggle_');
                    var btn = document.getElementById(prefix+id);
                    if(btn){
                        btn.textContent = isActive ? '✅ Ativo' : '⏸ Inativo';
                        btn.style.background = isActive ? 'rgba(34,211,160,.15)' : 'rgba(144,144,170,.12)';
                        btn.style.color = isActive ? 'var(--green)' : 'var(--text3)';
                        btn.style.border = isActive ? '1px solid rgba(34,211,160,.3)' : '1px solid var(--border)';
                    }
                    BP.toast(isActive ? 'Ativado!' : 'Inativado!');
                } else {
                    BP.toast(r.data?.message||'Erro','error');
                }
            });
        }

function bpOpenProForm(id,cid){
            BP.ajax('bp_app_action',{sub:'get_pro_form',pro_id:id,company_id:cid})
              .then(r=>{if(r.success)BP.openModal(r.data.html);});
        }


function bpOpenComanda(){
            BP.openModal(`
            <div class="bp-modal-header"><div class="bp-modal-title">🧾 Nova Comanda</div><button class="bp-modal-close" onclick="BP.closeModal()">✕</button></div>
            <div class="bp-modal-body">
                <div class="bp-field"><label>Número da Mesa</label><input type="text" id="bpCmdTable" placeholder="Ex: 1, 2, VIP..."></div>
                <div class="bp-field"><label>Nome do Cliente</label><input type="text" id="bpCmdClient" placeholder="Opcional"></div>
            </div>
            <div class="bp-modal-footer">
                <button class="bp-btn bp-btn-ghost" onclick="BP.closeModal()">Cancelar</button>
                <button class="bp-btn bp-btn-primary" onclick="bpConfirmOpenComanda()">Abrir Comanda</button>
            </div>`);
        }
        function bpConfirmOpenComanda(){
            var table=document.getElementById('bpCmdTable').value;
            var client=document.getElementById('bpCmdClient').value;
            BP.ajax('bp_app_action',{sub:'open_bar_comanda',table_number:table,client_name:client})
              .then(r=>{if(r.success){BP.closeModal();bpViewComanda(r.data.id);}else{BP.toast(r.data?.message||'Erro','error');}});
        }
        function bpViewComanda(id){
            BP.ajax('bp_app_action',{sub:'get_bar_comanda_view',comanda_id:id})
              .then(r=>{if(r.success)BP.openModal(r.data.html);else BP.toast('Erro','error');});
        }
        function bpPrintComanda(id){
            BPPrinter.print(id);
        }

function bpOpenProductForm(id){
            BP.ajax('bp_app_action',{sub:'get_product_form',product_id:id})
              .then(r=>{if(r.success)BP.openModal(r.data.html);});
        }
        function bpOpenStockMove(id,name){
            BP.openModal(`
            <div class="bp-modal-header"><div class="bp-modal-title">📦 Estoque – ${name}</div><button class="bp-modal-close" onclick="BP.closeModal()">✕</button></div>
            <div class="bp-modal-body">
                <div class="bp-field"><label>Tipo de Movimentação</label>
                    <select id="bpMoveType">
                        <option value="entrada">📥 Entrada (compra)</option>
                        <option value="ajuste">🔧 Ajuste manual</option>
                    </select>
                </div>
                <div class="bp-field"><label>Quantidade</label><input type="number" id="bpMoveQty" min="0.1" step="0.1" placeholder="0"></div>
                <div class="bp-field"><label>Observação</label><input type="text" id="bpMoveReason" placeholder="Motivo..."></div>
            </div>
            <div class="bp-modal-footer">
                <button class="bp-btn bp-btn-ghost" onclick="BP.closeModal()">Cancelar</button>
                <button class="bp-btn bp-btn-primary" onclick="bpConfirmStockMove(${id})">✅ Registrar</button>
            </div>`);
        }
        function bpConfirmStockMove(id){
            var type=document.getElementById('bpMoveType').value;
            var qty=document.getElementById('bpMoveQty').value;
            var reason=document.getElementById('bpMoveReason').value;
            BP.ajax('bp_app_action',{sub:'stock_move',product_id:id,move_type:type,qty:qty,reason:reason})
              .then(r=>{if(r.success){BP.closeModal();BP.toast('Estoque atualizado!');BP.navigate('bar_produtos');}else{BP.toast(r.data?.message||'Erro','error');}});
        }

var _bpCxQtds = {};
var _bpCxPrecos = {}; /* loaded via AJAX */

        function bpCxQty(id, delta) {
            _bpCxQtds[id] = Math.max(0, (_bpCxQtds[id]||0) + delta);
            var el = document.getElementById('bpCxQ_'+id);
            if(el) el.textContent = _bpCxQtds[id];
            bpCxAtualizaResumo();
        }

        function bpCxAtualizaResumo() {
            var itens=[], total=0;
            Object.entries(_bpCxQtds).forEach(function([id,qty]){
                if(qty>0 && _bpCxPrecos[id]){
                    itens.push(qty+'x '+_bpCxPrecos[id].nome);
                    total += qty * _bpCxPrecos[id].preco;
                }
            });
            var resumo = document.getElementById('bpCxResumo');
            if(resumo) {
                resumo.style.display = itens.length ? 'block' : 'none';
                var ri = document.getElementById('bpCxResumoItens');
                if(ri) ri.textContent = itens.join(', ');
                var rt = document.getElementById('bpCxTotal');
                if(rt) rt.textContent = bpMoney(total);
            }
        }

        function bpCaixaNovaComanda() {
            // Foca no painel rápido
            document.getElementById('bpCxTable')?.focus();
            document.getElementById('bpCxTable')?.scrollIntoView({behavior:'smooth',block:'center'});
        }

        function bpCaixaConfirmarRapida() {
            var table  = (document.getElementById('bpCxTable')?.value||'').trim();
            var client = (document.getElementById('bpCxClient')?.value||'').trim();

            // Monta lista de itens selecionados
            var itens = [];
            Object.entries(_bpCxQtds).forEach(function([id,qty]){
                if(qty>0) itens.push({product_id:parseInt(id), quantity:qty});
            });

            BP.ajax('bp_app_action',{
                sub:          'caixa_nova_comanda',
                table_number: table,
                client_name:  client,
                itens:        JSON.stringify(itens),
            }).then(function(r){
                if(r.success){
                    // Reset campos
                    if(document.getElementById('bpCxTable'))  document.getElementById('bpCxTable').value='';
                    if(document.getElementById('bpCxClient')) document.getElementById('bpCxClient').value='';
                    _bpCxQtds={};
                    document.querySelectorAll('[id^="bpCxQ_"]').forEach(function(el){el.textContent='0';});
                    bpCxAtualizaResumo();
                    BP.toast('Comanda aberta! '+(r.data?.code||''));
                    // Se tem itens, vai direto para cobrar; senão recarrega caixa
                    if(itens.length && r.data?.id) {
                        bpCaixaAbrirComanda(r.data.id);
                    } else {
                        BP.navigate('bar_caixa');
                    }
                } else {
                    BP.toast(r.data?.message||'Erro ao abrir comanda','error');
                }
            });
        }

        function bpCaixaAbrirComanda(id) {
            BP.ajax('bp_app_action',{sub:'get_bar_comanda_view',comanda_id:id})
              .then(function(r){ if(r.success) BP.openModal(r.data.html); });
        }

        function bpCaixaCobrar(id) {
            BP.ajax('bp_app_action',{sub:'get_bar_comanda_view',comanda_id:id})
              .then(function(r){
                if(r.success){
                    BP.openModal(r.data.html);
                    setTimeout(function(){
                        var pmt=document.getElementById('bpPmt_dinheiro');
                        if(pmt) pmt.scrollIntoView({behavior:'smooth',block:'center'});
                    },200);
                }
            });
        }

        function bpAdminImprimir(id){
            BPPrinter.print(id);
        }

function bpAdminSalvarProduto(){
            var name=document.getElementById('apf_name').value.trim();
            if(!name){BP.toast('Informe o nome','warn');return;}
            var price=document.getElementById('apf_price').value;
            if(!price){BP.toast('Informe o preço de venda','warn');return;}
            BP.ajax('bp_app_action',{
                sub:'save_product',
                product_id:document.getElementById('apf_id').value,
                name:name,
                category:document.getElementById('apf_cat').value,
                unit:document.getElementById('apf_unit').value,
                cost_price:document.getElementById('apf_cost').value,
                sale_price:price,
                stock_min:document.getElementById('apf_min').value||'0',
                stock_max:document.getElementById('apf_max').value||'0',
            }).then(function(r){
                if(r.success){BP.toast('Produto salvo!');BP.navigate('bar_admin','estoque');}
                else BP.toast(r.data?.message||'Erro','error');
            });
        }
        function bpAdminEditarProduto(id,name,cat,unit,cost,price,min,max){
            document.getElementById('apf_id').value=id;
            document.getElementById('apf_name').value=name;
            document.getElementById('apf_cat').value=cat;
            document.getElementById('apf_unit').value=unit;
            document.getElementById('apf_cost').value=cost.toFixed(2).replace('.',',');
            document.getElementById('apf_price').value=price.toFixed(2).replace('.',',');
            document.getElementById('apf_min').value=min;
            document.getElementById('apf_max').value=max;
            document.getElementById('apf_cancel').style.display='';
            document.getElementById('apf_name').scrollIntoView({behavior:'smooth',block:'center'});
            document.getElementById('apf_name').focus();
        }
        function bpAdminLimparProduto(){
            ['apf_id','apf_name','apf_cat','apf_cost','apf_price','apf_min','apf_max'].forEach(function(id){
                var el=document.getElementById(id); if(el) el.value=id==='apf_id'?'0':'';
            });
            document.getElementById('apf_cancel').style.display='none';
        }
        function bpAdminExcluirProduto(id){
            BP.ajax('bp_app_action',{sub:'bar_delete_product',product_id:id})
              .then(function(r){if(r.success){BP.toast('Produto excluído');BP.navigate('bar_admin','estoque');}else BP.toast('Erro','error');});
        }
        function bpAdminMovEstoque(){
            var pid=document.getElementById('apf_move_prod').value;
            var qty=document.getElementById('apf_move_qty').value;
            if(!pid){BP.toast('Selecione um produto','warn');return;}
            if(!qty||parseFloat(qty)<=0){BP.toast('Informe a quantidade','warn');return;}
            BP.ajax('bp_app_action',{
                sub:'stock_move',
                product_id:pid,
                move_type:document.getElementById('apf_move_type').value,
                qty:qty,
                reason:document.getElementById('apf_move_reason').value,
            }).then(function(r){
                if(r.success){BP.toast('Estoque atualizado!');BP.navigate('bar_admin','estoque');}
                else BP.toast(r.data?.message||'Erro','error');
            });
        }


function bpActivateLicense(){
            var key=document.getElementById('bpLicenseKey').value.trim();
            if(!key){BP.toast('Digite a chave','warn');return;}
            BP.ajax('bp_app_action',{sub:'activate_license',license_key:key})
              .then(r=>{if(r.success){BP.toast('Licença ativada!');BP.navigate('licenca');}else{BP.toast(r.data?.message||'Chave inválida','error');}});
        }

function bpSaveSettings(){
    var g = function(id){ var el=document.getElementById(id); return el?el.value:''; };
    var data = {
        sub:'save_settings',
        business_name:           g('cfg_business_name'),
        module_barbearia_name:   g('cfg_barber_name'),
        module_lavacar_name:     g('cfg_lavacar_name'),
        printer_name:            g('cfg_printer_name'),
        printer_width:           g('cfg_printer_width'),
        printer_copies:          g('cfg_printer_copies'),
        printer_header:          g('cfg_printer_header'),
        printer_footer:          g('cfg_printer_footer'),
        printer_enabled:         g('cfg_printer_enabled'),
    };
    BP.ajax('bp_app_action', data).then(function(r){
        if(r.success){
            bpLoadSettings({
                printer_name:    data.printer_name,
                printer_enabled: data.printer_enabled,
                printer_width:   data.printer_width,
                printer_copies:  data.printer_copies,
            });
            BP.toast('Configurações salvas!');
        } else {
            BP.toast('Erro ao salvar','error');
        }
    });
}

function bpAdminLoadSlots(){
            var date = document.getElementById('nb_date')&&document.getElementById('nb_date').value;
            var svc  = document.getElementById('nb_svc')&&document.getElementById('nb_svc').value;
            var pro  = document.getElementById('nb_pro')&&document.getElementById('nb_pro').value;
            var cid  = document.getElementById('nb_cid')&&document.getElementById('nb_cid').value;
            var wrap = document.getElementById('nb_slots_wrap');
            if(!wrap) return;
            if(!date||!svc){
                wrap.innerHTML='<span style="color:var(--text3);font-size:.85rem">Selecione data e serviço para ver os horários disponíveis.</span>';
                document.getElementById('nb_time').value='';
                return;
            }
            wrap.innerHTML='<span style="color:var(--text3);font-size:.85rem">Carregando horários...</span>';
            document.getElementById('nb_time').value='';
            BP.ajax('bp_app_action',{sub:'get_admin_slots',company_id:cid,professional_id:pro||0,service_id:svc,booking_date:date})
              .then(function(r){
                if(!r.success||!r.data.slots||r.data.slots.length===0){
                    wrap.innerHTML='<span style="color:var(--red);font-size:.85rem">Nenhum horário disponível nesta data.</span>';
                    return;
                }
                var html='<div style="display:flex;flex-wrap:wrap;gap:6px">';
                r.data.slots.forEach(function(s){
                    html+='<button type="button" class="bp-slot-btn" data-time="'+s+'" onclick="bpAdminSelectSlot(this,\''+s+'\')" style="padding:7px 14px;border-radius:8px;border:1px solid var(--border);background:var(--bg3);color:var(--text);cursor:pointer;font-size:.85rem;font-weight:600">'+s.substring(0,5)+'</button>';
                });
                html+='</div>';
                wrap.innerHTML=html;
              });
        }
        function bpAdminSelectSlot(el,time){
            document.querySelectorAll('.bp-slot-btn').forEach(function(b){
                b.style.background='var(--bg3)';b.style.color='var(--text)';b.style.borderColor='var(--border)';
            });
            el.style.background='var(--accent)';el.style.color='#fff';el.style.borderColor='var(--accent)';
            document.getElementById('nb_time').value=time;
        }
        function bpSaveBooking(){
            var cid  = document.getElementById('nb_cid').value;
            var name = document.getElementById('nb_name').value.trim();
            var date = document.getElementById('nb_date').value;
            var time = document.getElementById('nb_time').value;
            var svc  = document.getElementById('nb_svc').value;
            var pro  = document.getElementById('nb_pro').value;
            if(!name){BP.toast('Informe o nome do cliente','warn');return;}
            if(!date){BP.toast('Informe a data','warn');return;}
            if(!svc){BP.toast('Selecione um serviço','warn');return;}
            if(!time){BP.toast('Selecione um horário disponível','warn');return;}
            var mod=parseInt(cid)===1?'barbearia':'lavacar';
            BP.ajax('bp_app_action',{sub:'save_booking',company_id:cid,client_name:name,
                client_phone:document.getElementById('nb_phone').value,
                booking_date:date,booking_time:time,service_id:svc,
                professional_id:pro,notes:document.getElementById('nb_notes').value})
              .then(function(r){
                if(r.success){
                    BP.closeModal();
                    BP.toast('✅ Agendamento criado!');
                    // navega para agenda na data correta
                    BP.navigate(mod+'_agenda', null, {date:date});
                }else{
                    BP.toast((r.data&&r.data.message)?r.data.message:'Erro ao salvar','error');
                }
              });
        }

function bpSaveService(){
            BP.ajax('bp_app_action',{sub:'save_service',service_id:document.getElementById('sf_id').value,company_id:document.getElementById('sf_cid').value,name:document.getElementById('sf_name').value,description:document.getElementById('sf_desc').value,price:document.getElementById('sf_price').value,duration_minutes:document.getElementById('sf_dur').value})
              .then(r=>{if(r.success){BP.closeModal();BP.toast('Serviço salvo!');var mod=document.getElementById('sf_cid').value==='1'?'barbearia':'lavacar';BP.navigate(mod+'_servicos');}else{BP.toast(r.data?.message||'Erro','error');}});
        }

var _bpBarSelId=null;
var _bpBarSelPrice=0;
        function bpBarSelectProd(id,name,price){
            _bpBarSelId=id;
            _bpBarSelPrice=price;
            document.querySelectorAll('.bp-prod-card').forEach(function(c){
                c.classList.toggle('selected', parseInt(c.getAttribute('data-pid'))===parseInt(id));
            });
            var nm=document.getElementById('bpBarSelName');
            var pr=document.getElementById('bpBarSelPrice');
            var si=document.getElementById('bpBarSelInfo');
            var bt=document.getElementById('bpBarAddBtn');
            if(nm) nm.textContent=name;
            if(pr) pr.textContent=bpMoney(price);
            if(si) si.style.display='block';
            if(bt) bt.disabled=false;
        }
        function bpBarConfirmAdd(cid){
            if(!_bpBarSelId){BP.toast('Selecione um produto primeiro','warn');return;}
            var qtyEl=document.getElementById('bpBarQty');
            var qty=qtyEl?parseFloat(qtyEl.value)||1:1;
            if(qty<=0){BP.toast('Quantidade inválida','warn');return;}
            var btn=document.getElementById('bpBarAddBtn');
            if(btn){btn.disabled=true;btn.textContent='Aguarde...';}
            BP.ajax('bp_app_action',{sub:'bar_add_item',comanda_id:cid,product_id:_bpBarSelId,quantity:qty})
              .then(function(r){
                if(r.success){
                    _bpBarSelId=null; _bpBarSelPrice=0;
                    BP.toast('✅ Item adicionado!');
                    BP.ajax('bp_app_action',{sub:'get_bar_comanda_view',comanda_id:cid})
                      .then(function(r2){
                        if(r2.success){
                            BP.closeModal();
                            BP.openModal(r2.data.html);
                        }
                      });
                } else {
                    BP.toast((r.data&&r.data.message)?r.data.message:'Erro ao adicionar','error');
                    if(btn){btn.disabled=false;btn.textContent='+ Add';}
                }
              }).catch(function(){
                BP.toast('Erro de conexão','error');
                if(btn){btn.disabled=false;btn.textContent='+ Add';}
              });
        }
        function bpBarRemoveItem(itemId,cmdId){
            if(!confirm('Remover item?'))return;
            BP.ajax('bp_app_action',{sub:'bar_remove_item',item_id:itemId,comanda_id:cmdId})
              .then(function(r){
                if(r.success){
                    BP.ajax('bp_app_action',{sub:'get_bar_comanda_view',comanda_id:cmdId})
                      .then(function(r2){
                        if(r2.success){BP.closeModal();BP.openModal(r2.data.html);}
                      });
                }
              });
        }
        function bpBarCalcPaid(total){
            // Lê dinamicamente todos os inputs de pagamento presentes no modal
            var sum=0;
            document.querySelectorAll('[id^="bpPmt_"]').forEach(function(el){
                sum+=parseFloat(el.value.replace(',','.')||0);
            });
            var pa=document.getElementById('bpBarPaidAmt'),tr=document.getElementById('bpBarTroco');
            if(pa)pa.textContent=bpMoney(sum);
            if(tr){var troco=sum-total;tr.textContent=bpMoney(Math.max(0,troco));tr.style.color=troco<0?'var(--red)':'var(--green)';}
        }
        function bpBarPay(cid,total){
            var payments={},sum=0;
            document.querySelectorAll('[id^="bpPmt_"]').forEach(function(el){
                var m=el.id.replace('bpPmt_','');
                var v=parseFloat(el.value.replace(',','.')||0);
                if(v>0){payments[m]=v;sum+=v;}
            });
            if(Object.keys(payments).length===0){BP.toast('Informe ao menos um valor de pagamento','warn');return;}
            if(sum<total-0.01){BP.toast('Valor informado menor que o total (R$ '+bpMoney(total)+')','error');return;}
            var pmts=Object.entries(payments).map(function(e){return{method:e[0],amount:e[1]};});
            BP.ajax('bp_app_action',{sub:'bar_pay_comanda',comanda_id:cid,payments:JSON.stringify(pmts)})
              .then(function(r){
                if(r.success){BP.closeModal();BP.toast('✅ Pago! Receita lançada no financeiro');BP.navigate('bar_caixa');}
                else{BP.toast((r.data&&r.data.message)?r.data.message:'Erro ao pagar','error');}
              });
        }
        function bpBarCancel(cid){
            if(!confirm('Cancelar esta comanda? O estoque será estornado.'))return;
            BP.ajax('bp_app_action',{sub:'bar_cancel_comanda',comanda_id:cid})
              .then(function(r){
                if(r.success){BP.closeModal();BP.toast('Comanda cancelada');BP.navigate('bar_caixa');}
              });
        }
        function bpBarAguardandoPag(cid){
            BP.ajax('bp_app_action',{sub:'bar_aguardando_pag',comanda_id:cid})
              .then(function(r){
                if(r.success){
                    BP.closeModal();
                    BP.toast('💳 Comanda marcada — cliente paga na saída');
                    BP.navigate('bar_caixa');
                }else{
                    BP.toast((r.data&&r.data.message)?r.data.message:'Erro','error');
                }
              });
        }
        function bpBarReabrirComanda(cid){
            BP.ajax('bp_app_action',{sub:'bar_reabrir_comanda',comanda_id:cid})
              .then(function(r){
                if(r.success){
                    BP.toast('🔓 Comanda reaberta');
                    // Recarrega o modal com o estado atualizado
                    BP.ajax('bp_app_action',{sub:'get_bar_comanda_view',comanda_id:cid})
                      .then(function(rv){
                        if(rv.success){BP.openModal(rv.data.html);}
                      });
                }else{
                    BP.toast((r.data&&r.data.message)?r.data.message:'Erro','error');
                }
              });
        }

function bpSaveProduct(){
            BP.ajax('bp_app_action',{sub:'save_product',product_id:document.getElementById('prd_id').value,name:document.getElementById('prd_name').value,category:document.getElementById('prd_cat').value,unit:document.getElementById('prd_unit').value,cost_price:document.getElementById('prd_cost').value,sale_price:document.getElementById('prd_price').value,stock_min:document.getElementById('prd_min').value,stock_max:document.getElementById('prd_max').value})
              .then(r=>{if(r.success){BP.closeModal();BP.toast('Produto salvo!');BP.navigate('bar_produtos');}else{BP.toast('Erro','error');}});
        }


// ── Financeiro ────────────────────────────────────────────────────────────────

function bpFinTab(tab, cid) {
    BP.navigate((cid===1?'barbearia':'lavacar')+'_finance', tab);
}

function bpFinFilter() {
    var type   = document.getElementById('finFilterType')?.value   || '';
    var status = document.getElementById('finFilterStatus')?.value || '';
    document.querySelectorAll('#finTable tbody tr').forEach(function(tr) {
        var okType   = !type   || tr.dataset.type   === type;
        var okStatus = !status || tr.dataset.status === status;
        tr.style.display = (okType && okStatus) ? '' : 'none';
    });
}

function bpFinOpenLanc(cid) {
    BP.ajax('bp_app_action', {sub:'get_finance_form', company_id:cid, lancamento_id:0})
      .then(function(r){ if(r.success) BP.openModal(r.data.html); else BP.toast(r.data?.message||'Erro','error'); });
}

function bpFinEdit(id, cid) {
    BP.ajax('bp_app_action', {sub:'get_finance_form', company_id:cid, lancamento_id:id})
      .then(function(r){ if(r.success) BP.openModal(r.data.html); else BP.toast(r.data?.message||'Erro','error'); });
}

function bpFinTipoToggle(tipo) {
    document.getElementById('flType').value = tipo;
    var btnR = document.getElementById('flBtnReceita');
    var btnD = document.getElementById('flBtnDespesa');
    if(tipo==='receita'){
        btnR.className='bp-btn bp-btn-primary'; btnR.style.justifyContent='center';
        btnD.className='bp-btn bp-btn-ghost';   btnD.style.justifyContent='center';
    } else {
        btnD.className='bp-btn bp-btn-danger';  btnD.style.justifyContent='center';
        btnR.className='bp-btn bp-btn-ghost';   btnR.style.justifyContent='center';
    }
}

function bpFinSalvarLanc() {
    var desc = document.getElementById('flDesc')?.value?.trim();
    var amt  = document.getElementById('flAmount')?.value;
    if(!desc){ BP.toast('Preencha a descrição','warn'); return; }
    if(!amt || parseFloat(amt)<=0){ BP.toast('Valor inválido','warn'); return; }
    BP.ajax('bp_app_action', {
        sub:              'save_finance',
        id:               document.getElementById('flId').value,
        company_id:       document.getElementById('flCid').value,
        type:             document.getElementById('flType').value,
        description:      desc,
        amount:           amt,
        category_id:      document.getElementById('flCat').value,
        payment_method:   document.getElementById('flMethod').value,
        status:           document.getElementById('flStatus').value,
        competencia_date: document.getElementById('flDate').value,
        due_date:         document.getElementById('flDue').value,
        supplier:         document.getElementById('flSupplier').value,
        notes:            document.getElementById('flNotes').value,
    }).then(function(r){
        if(r.success){
            BP.closeModal();
            BP.toast('✅ Lançamento salvo!');
            var cid = parseInt(document.getElementById('flCid').value);
            BP.navigate((cid===1?'barbearia':'lavacar')+'_finance');
        } else {
            BP.toast(r.data?.message||'Erro ao salvar','error');
        }
    });
}

function bpFinPagar(id) {
    if(!confirm('Marcar como pago/recebido?')) return;
    BP.ajax('bp_app_action',{sub:'pagar_finance',id:id}).then(function(r){
        if(r.success){ BP.toast('✅ Marcado como pago!'); BP.navigate(BP.currentSection); }
        else BP.toast(r.data?.message||'Erro','error');
    });
}

function bpFinExcluir(id) {
    if(!confirm('Excluir este lançamento?')) return;
    BP.ajax('bp_app_action',{sub:'delete_finance',id:id}).then(function(r){
        if(r.success){ BP.toast('Excluído'); BP.navigate(BP.currentSection); }
        else BP.toast(r.data?.message||'Erro','error');
    });
}

function bpFinSelectCor(el, cor) {
    document.querySelectorAll('#catColors div').forEach(function(d){ d.style.border='2px solid transparent'; });
    el.style.border='2px solid white';
    document.getElementById('catColor').value = cor;
}

function bpFinSalvarCat(cid) {
    var name = document.getElementById('catName')?.value?.trim();
    if(!name){ BP.toast('Nome obrigatório','warn'); return; }
    BP.ajax('bp_app_action',{
        sub:'save_finance_cat',
        company_id: cid,
        type:  document.getElementById('catType').value,
        name:  name,
        color: document.getElementById('catColor').value,
    }).then(function(r){
        if(r.success){
            BP.toast('✅ Categoria salva!');
            BP.navigate((cid===1?'barbearia':'lavacar')+'_finance', 'categorias');
        } else { BP.toast(r.data?.message||'Erro','error'); }
    });
}

function bpFinExcluirCat(id, cid) {
    if(!confirm('Excluir categoria?')) return;
    BP.ajax('bp_app_action',{sub:'delete_finance_cat',id:id}).then(function(r){
        if(r.success){ BP.toast('Excluída'); BP.navigate((cid===1?'barbearia':'lavacar')+'_finance','categorias'); }
        else BP.toast(r.data?.message||'Não é possível excluir','error');
    });
}


// ═══════════════════════════════════════════════════════════════════
// 🖨️  QZ TRAY – Impressora Térmica Direta
// Docs: https://qz.io/api/
// ═══════════════════════════════════════════════════════════════════

function bpBarAddItem(comandaId){
    var pid = document.getElementById('bpBarSelProd') ? document.getElementById('bpBarSelProd').value : 0;
    var qty = document.getElementById('bpBarQty') ? parseFloat(document.getElementById('bpBarQty').value)||1 : 1;
    if(!pid){BP.toast('Selecione um produto','warn');return;}
    BP.ajax('bp_app_action',{sub:'bar_add_item',comanda_id:comandaId,product_id:pid,quantity:qty})
      .then(function(r){
        if(r.success){BP.closeModal();BP.toast('Item adicionado!');BP.navigate('bar_caixa');}
        else{BP.toast(r.data?.message||'Erro','error');}
      });
}


var BPPrinter = (function(){
    var _qz     = null;   // instância qz-tray
    var _ready  = false;
    var _cfg    = null;

    // Carrega script QZ Tray dinamicamente (só quando necessário)
    function loadQZ(cb){
        if(window.qz){ cb(null, window.qz); return; }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js';
        s.onload = function(){ cb(null, window.qz); };
        s.onerror= function(){ cb('Falha ao carregar QZ Tray.'); };
        document.head.appendChild(s);
    }

    // Conecta ao QZ Tray local (precisa estar rodando no PC)
    function connect(cb){
        loadQZ(function(err, qz){
            if(err){ cb && cb(err); return; }
            if(qz.websocket.isActive()){ _ready=true; cb && cb(null); return; }
            qz.websocket.connect({ retries:2, delay:1 })
              .then(function(){
                _ready = true;
                bpQzSetStatus('online');
                cb && cb(null);
              })
              .catch(function(e){
                _ready = false;
                bpQzSetStatus('offline');
                cb && cb(e.message||'QZ Tray não encontrado');
              });
        });
    }

    // Monta configuração da impressora
    function buildConfig(printerName, width){
        return window.qz.configs.create(printerName, {
            encoding: 'Cp1252',
            margins: { top:0, right:0, bottom:0, left:0 },
        });
    }

    // Converte as linhas do servidor em comandos ESC/POS
    function buildCommands(lines, cols){
        var ESC = '\x1B', GS = '\x1D';
        var cmds = [];

        // Init + codepage latin1
        cmds.push({ type:'raw', format:'plain', data: ESC+'@'+ESC+'t'+String.fromCharCode(16) });

        lines.forEach(function(ln){
            switch(ln.type){
                case 'center':
                    cmds.push({type:'raw',format:'plain',data: ESC+'a'+String.fromCharCode(1)});
                    cmds.push({type:'raw',format:'plain',data: ln.text+'\n'});
                    cmds.push({type:'raw',format:'plain',data: ESC+'a'+String.fromCharCode(0)});
                    break;
                case 'center-bold':
                    cmds.push({type:'raw',format:'plain',data: ESC+'a'+String.fromCharCode(1)+ESC+'E'+String.fromCharCode(1)});
                    cmds.push({type:'raw',format:'plain',data: ln.text+'\n'});
                    cmds.push({type:'raw',format:'plain',data: ESC+'E'+String.fromCharCode(0)+ESC+'a'+String.fromCharCode(0)});
                    break;
                case 'left':
                    cmds.push({type:'raw',format:'plain',data: ln.text+'\n'});
                    break;
                case 'lr':
                case 'lr-bold':
                    var pad = cols - ln.left.length - ln.right.length;
                    var line = ln.left + (pad>0?Array(pad+1).join(' '):'  ') + ln.right;
                    if(ln.type==='lr-bold')
                        cmds.push({type:'raw',format:'plain',data:ESC+'E'+String.fromCharCode(1)+line+'\n'+ESC+'E'+String.fromCharCode(0)});
                    else
                        cmds.push({type:'raw',format:'plain',data:line+'\n'});
                    break;
                case 'divider':
                    cmds.push({type:'raw',format:'plain',data:Array(cols+1).join('-')+'\n'});
                    break;
                case 'feed':
                    cmds.push({type:'raw',format:'plain',data:Array((ln.lines||3)+1).join('\n')});
                    // Corta o papel
                    cmds.push({type:'raw',format:'plain',data:GS+'V'+String.fromCharCode(1)});
                    break;
            }
        });
        return cmds;
    }

    // Função principal: imprime a comanda
    function printComanda(comandaId){
        var printerName = bpGetSetting('printer_name');
        var enabled     = bpGetSetting('printer_enabled');
        if(!enabled || enabled==='0'){
            BP.toast('Impressora não configurada. Vá em Configurações → Impressora.','warn');
            return;
        }
        if(!printerName){
            BP.toast('Configure o nome da impressora em Configurações.','warn');
            return;
        }

        BP.toast('🖨️ Enviando para impressora...');

        // Busca dados ESC/POS do servidor
        BP.ajax('bp_app_action',{sub:'get_escpos_receipt', comanda_id: comandaId})
          .then(function(r){
            if(!r.success){ BP.toast(r.data?.message||'Erro ao gerar cupom','error'); return; }

            var lines   = r.data.lines;
            var cols    = r.data.cols;
            var copies  = r.data.copies || 1;
            var cmds    = buildCommands(lines, cols);

            connect(function(err){
                if(err){
                    BP.toast('QZ Tray offline: '+err,'error');
                    // fallback: browser print
                    BP.ajax('bp_app_action',{sub:'get_bar_comanda_receipt',comanda_id:comandaId})
                      .then(function(rb){if(rb.success){document.getElementById('bpPrintArea').innerHTML=rb.data.html;window.print();}});
                    return;
                }
                var cfg = buildConfig(printerName, 58);
                var jobs = [];
                for(var i=0;i<copies;i++) jobs.push(window.qz.print(cfg, cmds));
                Promise.all(jobs)
                  .then(function(){ BP.toast('✅ Impresso!'); })
                  .catch(function(e){ BP.toast('Erro de impressão: '+(e.message||e),'error'); });
            });
        });
    }

    // Teste de impressora
    function printTest(){
        var printerName = bpGetSetting('printer_name');
        if(!printerName){ BP.toast('Configure o nome da impressora primeiro.','warn'); return; }

        connect(function(err){
            if(err){ BP.toast('QZ Tray offline: '+err,'error'); return; }
            var ESC='\x1B', GS='\x1D', cols=32;
            var cmds=[
                {type:'raw',format:'plain',data:ESC+'@'},
                {type:'raw',format:'plain',data:ESC+'a'+String.fromCharCode(1)+ESC+'E'+String.fromCharCode(1)},
                {type:'raw',format:'plain',data:'*** TESTE ***\n'},
                {type:'raw',format:'plain',data:ESC+'E'+String.fromCharCode(0)+ESC+'a'+String.fromCharCode(0)},
                {type:'raw',format:'plain',data:Array(cols+1).join('-')+'\n'},
                {type:'raw',format:'plain',data:'BarberPro SaaS\n'},
                {type:'raw',format:'plain',data:'Impressora: '+printerName+'\n'},
                {type:'raw',format:'plain',data:(new Date()).toLocaleString('pt-BR')+'\n'},
                {type:'raw',format:'plain',data:Array(cols+1).join('-')+'\n'},
                {type:'raw',format:'plain',data:'\n\n\n\n'},
                {type:'raw',format:'plain',data:GS+'V'+String.fromCharCode(1)},
            ];
            var cfg = buildConfig(printerName);
            window.qz.print(cfg, cmds)
              .then(function(){ BP.toast('✅ Teste impresso!'); })
              .catch(function(e){ BP.toast('Erro: '+(e.message||e),'error'); });
        });
    }

    return { connect:connect, print:printComanda, test:printTest };
})();

// Helper para ler setting do server (usa cache local após first load)
var _bpSettings = {};
function bpGetSetting(key){ return _bpSettings[key] || ''; }
function bpLoadSettings(data){ if(data) Object.assign(_bpSettings, data); }

// UI helpers
function bpQzSetStatus(status){
    var btn = document.getElementById('bpQzStatus');
    if(!btn) return;
    if(status==='online'){ btn.textContent='🟢 QZ Tray Conectado'; btn.style.color='var(--green)'; }
    else                  { btn.textContent='🔴 QZ Tray Offline';   btn.style.color='var(--red)'; }
}
function bpQzConnect(){ BPPrinter.connect(function(e){ if(e) BP.toast('QZ Tray: '+e,'error'); }); }
function bpPrinterTest(){ BPPrinter.test(); }
function bpPrinterToggle(){
    var cur = document.getElementById('cfg_printer_enabled');
    if(!cur) return;
    var on = cur.value !== '1';
    cur.value = on ? '1' : '0';
    var wrap  = document.getElementById('printerToggleWrap');
    var thumb = document.getElementById('printerToggleThumb');
    var txt   = document.getElementById('printerToggleTxt');
    if(wrap)  wrap.style.background  = on ? 'var(--accent)' : 'var(--border)';
    if(thumb) thumb.style.left       = on ? '21px' : '3px';
    if(txt)   txt.textContent        = on ? 'Ativo' : 'Inativo';
}
