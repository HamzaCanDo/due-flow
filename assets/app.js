(function () {
  const state = { archived: false, week: new Date(), customers: [] };
  const $ = (s) => document.querySelector(s);
  const money = (n) => '৳' + Number(n || 0).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const formatDateTime = (value) => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T') + '+06:00');
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat('en-BD', {
      timeZone: 'Asia/Dhaka',
      dateStyle: 'medium',
      timeStyle: 'short',
      hour12: true
    }).format(date);
  };
  const api = async (path, options = {}) => {
    options.headers = {'X-Requested-With':'fetch', ...(options.headers || {})};
    if (options.body instanceof FormData) options.body.append('csrf', window.DUE_FLOW.csrf);
    const r = await fetch(window.DUE_FLOW.base + '/?action=api/' + path, options);
    const data = await r.json(); if (!r.ok) throw new Error(data.error || 'Request failed'); return data;
  };
  const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  async function loadCustomers() {
    const data = await api('customers&archived=' + (state.archived ? '1' : '0'));
    state.customers = data.customers; $('#customers').innerHTML = state.customers.length ? state.customers.map(c => `<article class="card"><div class="card-head"><div><h3>${escapeHtml(c.name)}</h3><div class="meta">${escapeHtml(c.phone)}</div></div><div class="amount">${money(c.outstanding_due)}</div></div><div class="meta">Added ${escapeHtml(formatDateTime(c.created_at))}${c.archived_at ? '<br>Paid ' + escapeHtml(formatDateTime(c.archived_at)) : ''}${c.notes ? '<br>' + escapeHtml(c.notes) : ''}</div><div class="actions"><a class="call button" href="tel:${encodeURIComponent(c.phone)}">Call</a>${!state.archived ? `<button data-edit="${c.id}" class="secondary">Edit</button><button data-paid="${c.id}" class="secondary">Paid</button>` : ''}</div></article>`).join('') : '<div class="empty">No customers here yet.</div>';
  }
  function openForm(customer) { const f=$('#customer-form'); f.reset(); f.id.value=customer?.id||''; f.name.value=customer?.name||''; f.phone.value=customer?.phone||''; f.due.value=customer?.outstanding_due ?? ''; f.notes.value=customer?.notes||''; $('#form-title').textContent=customer?'Edit customer':'Add customer'; $('#customer-dialog').showModal(); }
  async function save(e) { e.preventDefault(); const f=e.target; const old=state.customers.find(c=>c.id===f.id.value); const next=Number(f.due.value); let confirmPayment='no'; if(old && next < Number(old.outstanding_due)){const diff=(Number(old.outstanding_due)-next).toFixed(2); if(!window.confirm(`Did you actually receive ৳${diff} from this customer?\\n\\nChoose OK for YES — Payment Received. Cancel saves only the corrected due.`)) confirmPayment='no'; else confirmPayment='yes';} const body=new FormData(f); body.append('confirm_payment',confirmPayment); try{await api('customer/save',{method:'POST',body}); $('#customer-dialog').close(); await loadCustomers();}catch(err){f.output.textContent=err.message;} }
  async function loadReport() { const d=state.week; const monday=new Date(d); monday.setDate(d.getDate()-((d.getDay()+6)%7)); const iso=monday.toISOString().slice(0,10); const r=await api('reports&week='+iso); $('#week-label').textContent=r.week_start+' – '+r.week_end; $('#given').textContent=money(r.given); $('#paid').textContent=money(r.paid); $('#paid-count').textContent=String(r.paid_count); $('#outstanding').textContent=money(r.outstanding); }
  async function loadRanking(){const r=await api('ranking');$('#ranking-list').innerHTML=r.ranking.length?r.ranking.map(c=>`<article class="card rank"><strong>${c.rank??'—'}</strong><p><b>${escapeHtml(c.name)}</b><small>${c.enough_history?'Given '+money(c.total_given)+' · Repaid '+money(c.total_repaid):'Not enough history'}<br>Balance ${money(c.outstanding_due)}</small></p><span class="score">${c.enough_history?c.score:'—'}</span></article>`).join(''):'<div class="empty">No repayment data yet.</div>';}
  $('#login')?.addEventListener('submit',async e=>{e.preventDefault();const out=e.target.output;try{const b=new FormData(e.target);const r=await fetch('?action=login',{method:'POST',body:b});const d=await r.json();if(!r.ok)throw Error(d.error);location.reload();}catch(err){out.textContent=err.message;}});
  if ($('#customers')) { $('#add').onclick=()=>openForm(); $('#cancel').onclick=()=>$('#customer-dialog').close(); $('#customer-form').onsubmit=save; $('#contacts').onclick=async()=>{if('contacts' in navigator){try{const [c]=await navigator.contacts.select(['name','tel'],{multiple:false});if(c){if(c.name?.[0])$('#customer-form').name.value=c.name[0];if(c.tel?.[0])$('#customer-form').phone.value=c.tel[0];}}catch(e){}}else alert('Contact selection is not supported by this browser. Please enter the name and phone manually.');}; document.addEventListener('click',async e=>{if(e.target.dataset.edit)openForm(state.customers.find(c=>c.id===e.target.dataset.edit));if(e.target.dataset.paid){const customer=state.customers.find(c=>c.id===e.target.dataset.paid);if(customer && confirm(`Mark ${customer.name} as paid and move them to Paid?\\n\\nThis records ${money(customer.outstanding_due)} as received.`)){const b=new FormData();b.append('id',customer.id);try{await api('customer/paid',{method:'POST',body:b});await loadCustomers();}catch(err){alert(err.message);}}}}); document.querySelectorAll('.tab').forEach(b=>b.onclick=()=>{document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));b.classList.add('active');state.archived=b.dataset.tab==='paid';loadCustomers();}); document.querySelectorAll('.bottom button').forEach(b=>b.onclick=()=>{document.querySelectorAll('.bottom button').forEach(x=>x.classList.remove('active'));b.classList.add('active');document.querySelectorAll('.view').forEach(x=>x.classList.remove('active'));$('#'+b.dataset.view).classList.add('active');if(b.dataset.view==='reports')loadReport();if(b.dataset.view==='ranking')loadRanking();}); $('#prev-week').onclick=()=>{state.week.setDate(state.week.getDate()-7);loadReport()};$('#next-week').onclick=()=>{state.week.setDate(state.week.getDate()+7);loadReport()};loadCustomers();}
})();
