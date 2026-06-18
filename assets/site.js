/* ===========================================================
   Infouno — interacciones compartidas
   =========================================================== */

/* Configurá tu número y mensajes acá (un solo lugar) */
var WAVE = String.fromCodePoint(0x1F44B); // 👋 a prueba de codificación
window.INFOUNO = {
  whatsapp: "5491159397079", // formato internacional sin "+" ni espacios
  msgDefault: "Hola Infouno " + WAVE + " Quiero un diagnóstico gratuito para mi negocio.",
  // Pegá acá el link de tu agendador (Cal.com, Calendly o Google Appointment Schedule).
  // Ejemplos:  "https://cal.com/infouno/15min"  ·  "https://calendly.com/infouno/15min"
  // Si lo dejás vacío, el bot y los botones siguen coordinando por WhatsApp.
  agenda: "https://cal.com/infouno/consultoria-15-min",
  // ID de medición de Google Analytics 4 (formato "G-XXXXXXXXXX").
  // Vacío = no se carga GA4 (el banner de cookies queda inerte). Pegá el ID real cuando
  // crees la propiedad GA4. Sin consentimiento del usuario NO se carga ni se setean cookies.
  ga4: "G-54V1PR8K7V",
};

function waLink(msg) {
  // Restaura emojis corruptos (�) que el navegador rompe al servir el archivo
  var clean = (msg || window.INFOUNO.msgDefault).replace(/\uFFFD/g, WAVE);
  const m = encodeURIComponent(clean);
  return `https://wa.me/${window.INFOUNO.whatsapp}?text=${m}`;
}

/* ===========================================================
   Persistencia de leads — guarda paso a paso en lead.php
   (no se pierde el lead aunque el usuario abandone)
   =========================================================== */
function leadSession() {
  var s = sessionStorage.getItem('leadSid');
  if (!s) { s = 'L' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8); sessionStorage.setItem('leadSid', s); }
  return s;
}
function captureUTM() {
  try {
    if (sessionStorage.getItem('leadUTM')) return;
    var p = new URLSearchParams(location.search), u = {};
    ['utm_source', 'utm_medium', 'utm_campaign'].forEach(function (k) { if (p.get(k)) u[k] = p.get(k); });
    if (Object.keys(u).length) sessionStorage.setItem('leadUTM', JSON.stringify(u));
  } catch (e) { }
}
function postLead(fields) {
  try {
    var utm = {}; try { utm = JSON.parse(sessionStorage.getItem('leadUTM') || '{}'); } catch (e) { }
    var payload = Object.assign({ session_id: leadSession(), page: location.pathname }, utm, fields || {});
    // keepalive permite que el envío sobreviva si el usuario cierra la pestaña
    fetch('/lead.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload), keepalive: true }).catch(function () { });
  } catch (e) { }
}

/* ===========================================================
   Agenda — modal con el agendador embebido (carga on-demand)
   Funciona con cualquier URL: Cal.com, Calendly o Google.
   =========================================================== */
function agendaConfigured() { return !!(window.INFOUNO && window.INFOUNO.agenda); }
function agendaUrl(prefill) {
  var url = window.INFOUNO.agenda;
  try {
    var q = [];
    if (/cal\.com/.test(url)) q.push('embed=true'); // modo embed optimizado de Cal.com
    if (prefill && prefill.name) q.push('name=' + encodeURIComponent(prefill.name));
    if (prefill && prefill.email) q.push('email=' + encodeURIComponent(prefill.email));
    if (q.length) url += (url.indexOf('?') > -1 ? '&' : '?') + q.join('&');
  } catch (e) { }
  return url;
}
function openAgenda(prefill) {
  // Sin agenda configurada → caemos a WhatsApp (nunca dejamos un botón muerto)
  if (!agendaConfigured()) { window.open(waLink(), '_blank', 'noopener'); return; }
  window.infoTrack('open_agenda', {});
  var ov = document.createElement('div'); ov.className = 'agenda-modal';
  ov.innerHTML = '<div class="agenda-box">'
    + '<button class="agenda-x" aria-label="Cerrar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>'
    + '<iframe title="Agendá tu consultoría" src="' + agendaUrl(prefill) + '" loading="lazy" allow="camera; microphone; fullscreen"></iframe>'
    + '</div>';
  function close() { ov.remove(); document.removeEventListener('keydown', onKey); }
  function onKey(e) { if (e.key === 'Escape') close(); }
  ov.addEventListener('click', e => { if (e.target === ov) close(); });
  ov.querySelector('.agenda-x').addEventListener('click', close);
  document.addEventListener('keydown', onKey);
  document.body.appendChild(ov);
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-year]').forEach(el => el.textContent = new Date().getFullYear());
  captureUTM();

  // Eventos de conversión → GA4 (vía infoTrack; no-op si no hay consentimiento).
  // Listener delegado en captura: cubre links estáticos y los que el bot crea al vuelo.
  document.addEventListener('click', (e) => {
    const a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    const href = a.getAttribute('href') || '';
    if (/wa\.me|api\.whatsapp/.test(href)) window.infoTrack('click_whatsapp', { link_url: href });
    else if (href.indexOf('tel:') === 0) window.infoTrack('click_phone', { link_url: href });
  }, true);

  // Cualquier elemento con [data-open-agenda] abre el agendador
  document.querySelectorAll('[data-open-agenda]').forEach(el => el.addEventListener('click', e => { e.preventDefault(); openAgenda(); }));

  document.querySelectorAll('[data-wa]').forEach(a => {
    const custom = a.getAttribute('data-wa');
    a.href = waLink(custom && custom.length ? custom : null);
    a.target = "_blank"; a.rel = "noopener";
  });

  const nav = document.querySelector('.nav');
  const toggle = document.querySelector('.nav__toggle');
  if (toggle && nav) {
    toggle.addEventListener('click', () => nav.classList.toggle('open'));
    nav.querySelectorAll('.nav__links a').forEach(a => a.addEventListener('click', () => nav.classList.remove('open')));
  }

  const io = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
  }, { threshold: 0.12, rootMargin: "0px 0px -40px 0px" });
  document.querySelectorAll('.reveal').forEach((el, i) => {
    el.style.transitionDelay = (Math.min(i % 4, 3) * 70) + 'ms';
    io.observe(el);
  });

  const form = document.querySelector('#contact-form');
  if (form) {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const d = new FormData(form);
      postLead({ source: 'form', name: d.get('nombre') || '', empresa: d.get('empresa') || '', interes: d.get('interes') || '', mensaje: d.get('mensaje') || '' });
      window.infoTrack('generate_lead', { method: 'form', interes: d.get('interes') || '' });
      const msg = `Hola Infouno 👋\n\nNombre: ${d.get('nombre') || ''}\nEmpresa: ${d.get('empresa') || ''}\nInterés: ${d.get('interes') || ''}\n\n${d.get('mensaje') || ''}`;
      window.open(waLink(msg), '_blank', 'noopener');
      const ok = document.querySelector('#form-ok');
      if (ok) { ok.style.display = 'flex'; form.reset(); }
    });
  }

  initCalc();
  initBot();
});

/* ===========================================================
   Calculadora de tiempo ahorrado
   =========================================================== */
function initCalc() {
  const wa = document.querySelector('#calc-wa');
  if (!wa) return;
  const stock = document.querySelector('#calc-stock');
  const cost = document.querySelector('#calc-cost');
  const fmtH = n => Number.isInteger(n) ? n : n.toFixed(1);
  const peso = n => '$' + Math.round(n).toLocaleString('es-AR');

  function paint(el) {
    const min = +el.min, max = +el.max, v = +el.value;
    el.style.setProperty('--p', ((v - min) / (max - min) * 100) + '%');
  }
  function calc() {
    const wH = +wa.value, sH = +stock.value, c = +cost.value;
    document.querySelector('#v-wa').textContent = fmtH(wH) + ' hs';
    document.querySelector('#v-stock').textContent = fmtH(sH) + ' hs';
    document.querySelector('#v-cost').textContent = peso(c);
    // automatizable: 70% del tiempo en WhatsApp + 85% del de stock
    const horasDia = wH * 0.7 + sH * 0.85;
    const horasMes = horasDia * 22;           // ~22 días hábiles
    const ahorro = horasMes * c;
    const costoAuto = 90000;                   // inversión mensual estimada referencial
    const roi = ahorro > 0 ? Math.max(0, ((ahorro - costoAuto) / costoAuto * 100)) : 0;
    document.querySelector('#r-horas').textContent = Math.round(horasMes) + ' hs';
    document.querySelector('#r-ahorro').textContent = peso(ahorro);
    document.querySelector('#r-roi').textContent = (ahorro <= costoAuto ? '+' + Math.round(ahorro / costoAuto * 100) : '+' + Math.round(roi)) + '%';
    [wa, stock, cost].forEach(paint);
  }
  [wa, stock, cost].forEach(el => el.addEventListener('input', calc));
  calc();

  const cta = document.querySelector('#calc-cta');
  if (cta) {
    cta.addEventListener('click', () => {
      const horas = document.querySelector('#r-horas').textContent;
      const ahorro = document.querySelector('#r-ahorro').textContent;
      cta.href = waLink(`Hola Infouno 👋 Usé la calculadora: podría ahorrar ${horas} y ${ahorro} por mes. Quiero el análisis completo de automatización.`);
      cta.target = '_blank'; cta.rel = 'noopener';
    });
  }
}

/* ===========================================================
   Chatbot "Uno" — captador de leads
   =========================================================== */
function initBot() {
  const bot = document.querySelector('#bot');
  if (!bot) return;
  const fab = document.querySelector('#bot-fab');
  const body = document.querySelector('#bot-body');
  const foot = document.querySelector('#bot-foot');
  const botIco = '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="16" height="11" rx="3"/><path d="M12 8V5M9 3h6"/><circle cx="9" cy="13" r="1"/><circle cx="15" cy="13" r="1"/></svg>';
  const lead = {};
  let step = 0, opened = false, autoTimer;
  // Guarda el estado actual del lead en cada paso (no se pierde si abandona)
  function persist() { postLead({ source: 'bot', name: lead.nombre, rubro: lead.rubro, web: lead.web, equipo: lead.equipo, whatsapp: lead.whatsapp, email: lead.email }); }
  // Evento de conversión: el bot llegó al cierre con un lead. Una sola vez por sesión.
  let leadTracked = false;
  function trackLead() { if (leadTracked) return; leadTracked = true; window.infoTrack('bot_lead_captured', { rubro: lead.rubro || '', equipo: lead.equipo || '', web: lead.web || '' }); }

  function open() {
    bot.classList.add('open'); fab.classList.add('hide'); opened = true;
    clearTimeout(autoTimer);
    if (step === 0) startBot();
  }
  function close() { bot.classList.remove('open'); fab.classList.remove('hide'); }
  fab.addEventListener('click', open);
  document.querySelector('#bot-x').addEventListener('click', close);
  document.querySelectorAll('[data-open-bot]').forEach(b => b.addEventListener('click', e => { e.preventDefault(); open(); }));

  // apertura automática a los 5s
  autoTimer = setTimeout(() => { if (!opened && !sessionStorage.getItem('botSeen')) { open(); sessionStorage.setItem('botSeen', '1'); } }, 5000);

  // exit-intent: si el cursor sale por arriba (intención de cerrar/cambiar de pestaña) abre el bot
  function exitIntent(e) {
    if (opened || sessionStorage.getItem('botSeen')) return;
    if (e.clientY <= 0) { open(); sessionStorage.setItem('botSeen', '1'); document.removeEventListener('mouseleave', exitIntent); }
  }
  document.addEventListener('mouseleave', exitIntent);

  function scroll() { body.scrollTop = body.scrollHeight; }
  function botSay(html, cb) {
    const t = document.createElement('div'); t.className = 'bmsg'; t.innerHTML = `<div class="bav">${botIco}</div><div class="bb btyping-wrap"><span class="btyping"><i></i><i></i><i></i></span></div>`;
    body.appendChild(t); scroll();
    setTimeout(() => { t.querySelector('.bb').innerHTML = html; scroll(); cb && cb(); }, 700);
  }
  function meSay(text) {
    const m = document.createElement('div'); m.className = 'bmsg me'; m.innerHTML = `<div class="bb"></div>`;
    m.querySelector('.bb').textContent = text; body.appendChild(m); scroll();
  }
  function options(opts) {
    foot.innerHTML = ''; const wrap = document.createElement('div'); wrap.className = 'bot__opts';
    opts.forEach(o => { const b = document.createElement('button'); b.className = 'bot__opt'; b.textContent = o.label; b.onclick = () => { meSay(o.label); o.go(o.label); }; wrap.appendChild(b); });
    foot.appendChild(wrap);
  }
  function ask(placeholder, go) {
    foot.innerHTML = '';
    const row = document.createElement('div'); row.className = 'bot__inrow';
    row.innerHTML = `<input type="text" placeholder="${placeholder}"><button aria-label="Enviar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg></button>`;
    const inp = row.querySelector('input'), btn = row.querySelector('button');
    const send = () => { const v = inp.value.trim(); if (!v) return; meSay(v); go(v); };
    btn.onclick = send; inp.addEventListener('keydown', e => { if (e.key === 'Enter') send(); });
    foot.appendChild(row); setTimeout(() => inp.focus(), 100);
  }
  function clearFoot() { foot.innerHTML = ''; }

  // Burbuja "pensando" que se rellena cuando llega la respuesta async
  function thinking() {
    const t = document.createElement('div'); t.className = 'bmsg';
    t.innerHTML = `<div class="bav">${botIco}</div><div class="bb btyping-wrap"><span class="btyping"><i></i><i></i><i></i></span></div>`;
    body.appendChild(t); scroll(); return t;
  }
  // Rellena como TEXTO PLANO (G3: nunca innerHTML con texto del modelo)
  function fillText(t, text) {
    const bb = t.querySelector('.bb'); bb.classList.remove('btyping-wrap');
    bb.textContent = text; scroll();
  }
  // Botones de cierre (agenda + WhatsApp). Compartido por guion e IA.
  function renderCierre() {
    clearFoot(); trackLead();
    const wrap = document.createElement('div'); wrap.style.display = 'flex'; wrap.style.flexDirection = 'column'; wrap.style.gap = '8px';
    const summary = `Hola Infouno 👋 Soy ${lead.nombre || ''}.\nRubro: ${lead.rubro || ''}\nWeb: ${lead.web || ''}\nEquipo: ${lead.equipo || ''}\nMi WhatsApp: ${lead.whatsapp || ''}${lead.email ? '\nEmail: ' + lead.email : ''}\nQuiero agendar la consultoría gratuita de 15 min.`;
    if (agendaConfigured()) {
      const cal = document.createElement('button'); cal.type = 'button'; cal.className = 'btn btn--block';
      cal.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg> Agendar mi reunión';
      cal.addEventListener('click', () => openAgenda({ name: lead.nombre, email: lead.email }));
      wrap.appendChild(cal);
    }
    const a = document.createElement('a'); a.href = waLink(summary); a.target = '_blank'; a.rel = 'noopener';
    a.className = agendaConfigured() ? 'btn btn--ghost btn--block' : 'btn btn--wa btn--block';
    a.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.86 9.86 0 0 0 12.04 2Z"/></svg> '
      + (agendaConfigured() ? 'O coordinar por WhatsApp' : 'Confirmar por WhatsApp');
    wrap.appendChild(a);
    foot.appendChild(wrap);
  }

  // ---- Modo IA: conversación libre ----
  const aiHistory = [];
  let aiTurns = 0, aiBusy = false;
  const AI_GREETING = '¡Hola! 👋 Soy Uno, el asistente de Infouno. Contame, ¿a qué se dedica tu negocio? Así te muestro cómo podemos ayudarte.';

  function aiStart() {
    step = 1;
    const t = thinking();
    setTimeout(() => {
      fillText(t, AI_GREETING);
      aiHistory.push({ role: 'assistant', content: AI_GREETING });
      aiInput();
    }, 500);
  }

  function aiInput() {
    foot.innerHTML = '';
    const row = document.createElement('div'); row.className = 'bot__inrow';
    row.innerHTML = `<input type="text" placeholder="Escribí tu mensaje…"><button aria-label="Enviar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg></button>`;
    const inp = row.querySelector('input'), btn = row.querySelector('button');
    const send = () => {
      const v = inp.value.trim();
      if (!v || aiBusy) return;
      meSay(v); aiSend(v);
    };
    btn.onclick = send; inp.addEventListener('keydown', e => { if (e.key === 'Enter') send(); });
    foot.appendChild(row); setTimeout(() => inp.focus(), 100);
  }

  function aiSend(text) {
    aiBusy = true; aiTurns++;
    aiHistory.push({ role: 'user', content: text });
    let utm = {};
    try { utm = JSON.parse(sessionStorage.getItem('leadUTM') || '{}'); } catch (e) { }
    const t = thinking();
    fetch('/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ session_id: leadSession(), page: location.pathname, messages: aiHistory }, utm))
    })
      .then(r => r.json())
      .then(d => {
        aiBusy = false;
        if (!d || d.ok === false || (!d.reply && !d.readyToClose)) { fillText(t, 'Uy, tuve un problemita. Sigamos por WhatsApp 👇'); renderCierre(); return; }
        if (d.leadFields) Object.assign(lead, mapLeadFields(d.leadFields));
        if (d.reply) { fillText(t, d.reply); aiHistory.push({ role: 'assistant', content: d.reply }); }
        else { t.remove(); }
        if (d.readyToClose) renderCierre(); else aiInput();
      })
      .catch(() => { aiBusy = false; fillText(t, 'Uy, se me cortó la conexión. Coordinemos por WhatsApp 👇'); renderCierre(); });
  }

  // Mapea los campos que devuelve chat.php (en/es) al objeto lead local
  function mapLeadFields(f) {
    return { nombre: f.name, rubro: f.rubro, web: f.web, equipo: f.equipo, whatsapp: f.whatsapp, email: f.email };
  }

  // Decide el modo: IA si chat.php está habilitado, si no el guion scripteado
  function startBot() {
    step = 1;
    const t = thinking();
    let settled = false;
    const ctrl = ('AbortController' in window) ? new AbortController() : null;
    const finishOnce = (fn) => { if (settled) return; settled = true; clearTimeout(timer); t.remove(); step = 0; fn(); };
    const timer = setTimeout(() => { if (ctrl) ctrl.abort(); finishOnce(flow); }, 3500);
    fetch('/chat.php', ctrl ? { signal: ctrl.signal } : undefined)
      .then(r => r.json())
      .then(d => finishOnce((d && d.enabled) ? aiStart : flow))
      .catch(() => finishOnce(flow));
  }

  function flow() {
    step = 1;
    botSay('¡Hola! 👋 Soy <b>Uno</b>, el asistente IA de Infouno. Decime: ¿a qué se dedica tu negocio?', () => {
      ask('Ej: estudio contable, tienda de ropa…', v => { lead.rubro = v; persist(); step2(); });
    });
  }
  // R2: capturamos nombre + rubro ANTES de dar cualquier ejemplo o solución personalizada
  function step2() {
    botSay('¡Buenísimo! 🙌 Antes de seguir, ¿cómo es tu nombre?', () => {
      ask('Tu nombre', v => { lead.nombre = v; persist(); step3(); });
    });
  }
  // Recién con nombre + rubro damos el diagnóstico personalizado
  function step3() {
    botSay(`Un gusto, <b>${escapeHtml(lead.nombre)}</b>. En un rubro como <b>${escapeHtml(lead.rubro)}</b>, lo más común es que se vaya mucho tiempo respondiendo consultas repetitivas y coordinando turnos. 🕐`, () => {
      botSay('Con nuestros sistemas, el bot filtra la urgencia, responde al instante y te deja la reunión agendada sin que muevas un dedo. Para afinar la propuesta: ¿tenés web actualmente o arrancás de cero?', () => {
        options([
          { label: 'Ya tengo web', go: v => { lead.web = v; persist(); step4(); } },
          { label: 'Arranco de cero', go: v => { lead.web = v; persist(); step4(); } },
          { label: 'Tengo, pero quiero rehacerla', go: v => { lead.web = v; persist(); step4(); } },
        ]);
      });
    });
  }
  // R3: tres tramos de tamaño para poder detectar el "Lead VIP" (+5 personas)
  function step4() {
    botSay('Perfecto. ¿Cómo manejás el negocio hoy?', () => {
      options([
        { label: 'Lo manejo solo / a', go: v => { lead.equipo = v; persist(); step5(); } },
        { label: 'Equipo chico (2 a 5)', go: v => { lead.equipo = v; persist(); step5(); } },
        { label: 'Equipo grande (+5)', go: v => { lead.equipo = v; persist(); step5(); } },
      ]);
    });
  }
  function step5() {
    botSay('Para no quitarte tiempo, lo ideal es una <b>consultoría gratuita de 15 min</b> con nuestro equipo humano. 📅 Dejame tu WhatsApp y coordinamos.<br><span class="bot__legal">Al dejar tus datos aceptás nuestra <a href="privacidad.html" target="_blank" rel="noopener">política de privacidad</a> (Ley 25.326). Los usamos solo para contactarte.</span>', () => {
      ask('Tu WhatsApp (ej: 11 5555 5555)', v => { lead.whatsapp = v; persist(); step6(); });
    });
  }
  // Email opcional → habilita el envío del link de Google Meet (Check de dominio en lead.php)
  function step6() {
    botSay('¿A qué <b>email</b> te mando la confirmación y el link de Google Meet? Es opcional, pero así te llega todo por escrito. 📩', () => {
      foot.innerHTML = '';
      const row = document.createElement('div'); row.className = 'bot__inrow';
      row.innerHTML = `<input type="email" placeholder="tucorreo@ejemplo.com"><button aria-label="Enviar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg></button>`;
      const inp = row.querySelector('input'), btn = row.querySelector('button');
      const send = () => { const v = inp.value.trim(); if (!v) return; meSay(v); lead.email = v; persist(); finish(); };
      btn.onclick = send; inp.addEventListener('keydown', e => { if (e.key === 'Enter') send(); });
      foot.appendChild(row);
      const skip = document.createElement('button'); skip.className = 'bot__skip'; skip.type = 'button';
      skip.textContent = 'Prefiero coordinar solo por WhatsApp';
      skip.onclick = () => { meSay('Prefiero solo WhatsApp'); finish(); };
      foot.appendChild(skip);
      setTimeout(() => inp.focus(), 100);
    });
  }
  function finish() {
    clearFoot(); trackLead();
    const closing = agendaConfigured()
      ? `¡Listo, <b>${escapeHtml(lead.nombre || '')}</b>! 🙌 Elegí el día y horario que mejor te quede y queda agendado al instante. Si preferís, también podés coordinar por WhatsApp.`
      : `¡Listo, <b>${escapeHtml(lead.nombre || '')}</b>! 🙌 Ya tengo todo. Te paso a coordinar el día y horario directamente por WhatsApp.`;
    botSay(closing, () => {
      const summary = `Hola Infouno 👋 Soy ${lead.nombre || ''}.\nRubro: ${lead.rubro || ''}\nWeb: ${lead.web || ''}\nEquipo: ${lead.equipo || ''}\nMi WhatsApp: ${lead.whatsapp || ''}${lead.email ? '\nEmail: ' + lead.email : ''}\nQuiero agendar la consultoría gratuita de 15 min.`;

      if (agendaConfigured()) {
        const cal = document.createElement('button'); cal.type = 'button';
        cal.className = 'btn btn--block'; cal.style.marginTop = '4px';
        cal.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg> Agendar mi reunión';
        cal.addEventListener('click', () => openAgenda({ name: lead.nombre, email: lead.email }));
        foot.appendChild(cal);
      }

      const a = document.createElement('a'); a.href = waLink(summary); a.target = '_blank'; a.rel = 'noopener';
      a.className = agendaConfigured() ? 'btn btn--ghost btn--block' : 'btn btn--wa btn--block';
      a.style.marginTop = agendaConfigured() ? '8px' : '4px';
      a.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.86 9.86 0 0 0 12.04 2Z"/></svg> '
        + (agendaConfigured() ? 'O coordinar por WhatsApp' : 'Confirmar por WhatsApp');
      foot.appendChild(a);
    });
  }
  function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
}

/* ===========================================================
   Consentimiento de cookies + Google Consent Mode v2 + GA4
   Opt-in (Ley 25.326 / G4): NO se carga GA4 ni se setean cookies
   de analytics hasta que el usuario acepta. Sin ID en INFOUNO.ga4
   el banner no aparece (no hay nada que consentir).
   =========================================================== */
(function () {
  var KEY = 'infouno_consent';                 // 'granted' | 'denied'
  var GA_ID = (window.INFOUNO && window.INFOUNO.ga4) || '';

  // dataLayer + gtag disponibles siempre (sin red): habilita Consent Mode v2.
  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }
  window.gtag = gtag;

  // Consent Mode v2: por defecto TODO denegado hasta una decisión explícita.
  gtag('consent', 'default', {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: 'denied',
    wait_for_update: 500
  });

  function stored() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function remember(v) { try { localStorage.setItem(KEY, v); } catch (e) { } }

  // Carga la librería de GA4 (solo tras consentimiento). Idempotente.
  var loaded = false;
  function loadGA() {
    if (loaded || !GA_ID) return; loaded = true;
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA_ID);
    document.head.appendChild(s);
    gtag('js', new Date());
    gtag('config', GA_ID, { anonymize_ip: true });
  }

  function grant() {
    gtag('consent', 'update', {
      ad_storage: 'granted', ad_user_data: 'granted',
      ad_personalization: 'granted', analytics_storage: 'granted'
    });
    loadGA();
  }

  function decide(v) { remember(v); if (v === 'granted') grant(); hideBanner(); }

  // Helper de eventos de conversión (lo usa la Tarea 4). Solo dispara con
  // consentimiento aceptado y GA4 configurado; si no, no-op silencioso.
  window.infoTrack = function (name, params) {
    try { if (stored() === 'granted' && GA_ID) gtag('event', name, params || {}); } catch (e) { }
  };

  var banner;
  function hideBanner() { if (banner) banner.classList.remove('show'); }
  function showBanner() {
    if (banner) { banner.classList.add('show'); return; }
    banner = document.createElement('div');
    banner.className = 'consent'; banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-label', 'Aviso de cookies');
    banner.innerHTML =
      '<p class="consent__txt">Usamos cookies de medición (Google Analytics) para entender cómo se usa el sitio y mejorarlo. Son opcionales. Más info en nuestra <a href="privacidad.html">política de privacidad</a> (Ley 25.326).</p>'
      + '<div class="consent__row">'
      + '<button type="button" class="btn btn--ghost consent__btn" data-consent="denied">Rechazar</button>'
      + '<button type="button" class="btn btn--primary consent__btn" data-consent="granted">Aceptar</button>'
      + '</div>';
    banner.querySelectorAll('[data-consent]').forEach(function (b) {
      b.addEventListener('click', function () { decide(b.getAttribute('data-consent')); });
    });
    (document.body || document.documentElement).appendChild(banner);
    requestAnimationFrame(function () { banner.classList.add('show'); });
  }

  function init() {
    if (!GA_ID) return;                 // sin GA4 configurado no hay nada que consentir
    var prev = stored();
    if (prev === 'granted') { grant(); return; }  // ya aceptó: cargar GA4, sin banner
    if (prev === 'denied') return;                // ya rechazó: respetar y no insistir
    showBanner();                                 // sin decisión previa: pedir consentimiento
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

/* ===========================================================
   Tweaks — tema, color de acento y tipografía (todas las páginas)
   =========================================================== */
(function () {
  const PRESETS = {
    acero: { c: '#3E9BE6', set: { '--acc': '#3E9BE6', '--acc-2': '#62B4F2', '--acc-deep': '#2A6FB5', '--acc-soft': 'rgba(62,155,230,.14)', '--acc-line': 'rgba(62,155,230,.30)', '--glow': 'rgba(46,120,196,.40)' } },
    cielo: { c: '#4D8DF5', set: { '--acc': '#4D8DF5', '--acc-2': '#7AA6FF', '--acc-deep': '#2F5FD0', '--acc-soft': 'rgba(77,141,245,.14)', '--acc-line': 'rgba(77,141,245,.30)', '--glow': 'rgba(60,100,210,.42)' } },
    gris: { c: '#7E97B6', set: { '--acc': '#7E97B6', '--acc-2': '#9DB2CC', '--acc-deep': '#52688A', '--acc-soft': 'rgba(126,151,182,.16)', '--acc-line': 'rgba(126,151,182,.32)', '--glow': 'rgba(90,115,150,.40)' } },
    petroleo: { c: '#1FA6B0', set: { '--acc': '#23AEB8', '--acc-2': '#48C8D0', '--acc-deep': '#157A82', '--acc-soft': 'rgba(35,174,184,.14)', '--acc-line': 'rgba(35,174,184,.30)', '--glow': 'rgba(20,140,150,.40)' } }
  };
  const FONTS = {
    'Space Grotesk': { label: 'Space Grotesk', stack: "'Space Grotesk',system-ui,sans-serif", load: false },
    Sora: { label: 'Sora', stack: "'Sora',system-ui,sans-serif", load: 'Sora:wght@500;600;700' },
    'Plus Jakarta Sans': { label: 'Plus Jakarta Sans', stack: "'Plus Jakarta Sans',system-ui,sans-serif", load: 'Plus+Jakarta+Sans:wght@600;700;800' }
  };
  const KEY = 'infouno_tweaks';
  const def = { theme: 'dark', accent: 'acero', headFont: 'Space Grotesk' };
  function load() { try { return Object.assign({}, def, JSON.parse(localStorage.getItem(KEY) || '{}')); } catch (e) { return Object.assign({}, def); } }
  function save(s) { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) { } try { window.parent.postMessage({ type: '__edit_mode_set_keys', edits: s }, '*'); } catch (e) { } }

  const loaded = {};
  function ensureFont(key) {
    const f = FONTS[key]; if (!f || !f.load || loaded[key]) return; loaded[key] = true;
    const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'https://fonts.googleapis.com/css2?family=' + f.load + '&display=swap'; document.head.appendChild(l);
  }
  function apply(s) {
    const r = document.documentElement;
    if (s.theme === 'light') r.setAttribute('data-theme', 'light'); else r.removeAttribute('data-theme');
    const p = PRESETS[s.accent] || PRESETS.acero; Object.entries(p.set).forEach(([k, v]) => r.style.setProperty(k, v));
    const f = FONTS[s.headFont] || FONTS['Space Grotesk']; ensureFont(s.headFont); r.style.setProperty('--ff-head', f.stack);
  }

  let state = load(); apply(state);

  let built = false, panel;
  function build() {
    if (built) return; built = true;
    panel = document.createElement('div'); panel.className = 'tw-panel';
    panel.innerHTML = `
      <div class="tw-head"><b>Tweaks</b>
        <button class="tw-x" aria-label="Cerrar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
      </div>
      <div class="tw-body">
        <div class="tw-group"><div class="tw-label">Tema</div>
          <div class="tw-theme" id="tw-theme">
            <button data-theme-opt="dark">Oscuro</button>
            <button data-theme-opt="light">Claro</button>
          </div>
        </div>
        <div class="tw-group"><div class="tw-label">Color de acento</div>
          <div class="tw-swatches" id="tw-sw">
            ${Object.entries(PRESETS).map(([k, p]) => `<button class="tw-sw" data-accent="${k}" style="background:${p.c}"></button>`).join('')}
          </div>
        </div>
        <div class="tw-group"><div class="tw-label">Tipografía de títulos</div>
          <div class="tw-fonts" id="tw-fonts">
            ${Object.entries(FONTS).map(([k, f]) => `<button class="tw-font" data-font="${k}" style="font-family:${f.stack}">${f.label}</button>`).join('')}
          </div>
        </div>
        <button class="tw-reset" id="tw-reset">Restablecer</button>
      </div>`;
    document.body.appendChild(panel);
    panel.querySelector('.tw-x').addEventListener('click', hide);
    panel.querySelectorAll('[data-theme-opt]').forEach(b => b.addEventListener('click', () => { state.theme = b.dataset.themeOpt; apply(state); save(state); sync(); }));
    panel.querySelectorAll('[data-accent]').forEach(b => b.addEventListener('click', () => { state.accent = b.dataset.accent; apply(state); save(state); sync(); }));
    panel.querySelectorAll('[data-font]').forEach(b => b.addEventListener('click', () => { state.headFont = b.dataset.font; apply(state); save(state); sync(); }));
    panel.querySelector('#tw-reset').addEventListener('click', () => { state = Object.assign({}, def); apply(state); save(state); sync(); });
    sync();
  }
  function sync() {
    if (!panel) return;
    panel.querySelectorAll('[data-theme-opt]').forEach(b => b.classList.toggle('on', b.dataset.themeOpt === state.theme));
    panel.querySelectorAll('[data-accent]').forEach(b => b.classList.toggle('on', b.dataset.accent === state.accent));
    panel.querySelectorAll('[data-font]').forEach(b => b.classList.toggle('on', b.dataset.font === state.headFont));
  }
  function show() { build(); panel.classList.add('show'); }
  function hide() { if (panel) panel.classList.remove('show'); try { window.parent.postMessage({ type: '__edit_mode_dismissed' }, '*'); } catch (e) { } }

  window.addEventListener('message', (e) => {
    const t = e.data && e.data.type;
    if (t === '__activate_edit_mode') show(); else if (t === '__deactivate_edit_mode') hide();
  });
  try { window.parent.postMessage({ type: '__edit_mode_available' }, '*'); } catch (e) { }
})();
