/*
 * ADHUB — réglages communs des graphiques et petits comportements de l'interface.
 * Chargé dans <head>, avant les scripts des pages.
 */
(function () {
  var C = window.ADHUB = {
    colors: {
      navy: '#0E1C2B', blue: '#1F5FAD', blueSoft: 'rgba(31,95,173,.12)', gold: '#C8A84B',
      green: '#0F7B55', red: '#C2362F', grey: '#CBD2DB', muted: '#6B7280', grid: '#EEF0F3',
      series: ['#1F5FAD', '#C8A84B', '#0F7B55', '#7C5CC4', '#D9673B', '#2BA0B5', '#9CA3AF']
    },
    /** 12 345 € */
    eur: function (v, dec) {
      return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: dec || 0, minimumFractionDigits: dec || 0 }).format(v) + ' €';
    },
    eurShort: function (v) {
      var a = Math.abs(v);
      if (a >= 1e6) return (v / 1e6).toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' M€';
      if (a >= 1e3) return (v / 1e3).toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' k€';
      return Math.round(v) + ' €';
    },
    /** Mini-courbe sans axes, pour les tuiles d'indicateurs */
    spark: function (id, data, color) {
      var el = document.getElementById(id);
      if (!el || !window.Chart) return;
      var ctx = el.getContext('2d');
      var g = ctx.createLinearGradient(0, 0, 0, 40);
      g.addColorStop(0, (color || C.colors.blue) + '40'); g.addColorStop(1, (color || C.colors.blue) + '00');
      return new Chart(el, {
        type: 'line',
        data: { labels: data.map(function (_, i) { return i; }), datasets: [{ data: data, borderColor: color || C.colors.blue, backgroundColor: g, fill: true, borderWidth: 1.6, pointRadius: 0, tension: .35 }] },
        options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { display: false }, tooltip: { enabled: false } }, scales: { x: { display: false }, y: { display: false } } }
      });
    }
  };

  if (window.Chart) {
    var d = Chart.defaults;
    d.font.family = "'Inter', system-ui, sans-serif";
    d.font.size = 12;
    d.color = C.colors.muted;
    d.borderColor = C.colors.grid;
    d.maintainAspectRatio = false;
    d.plugins.legend.labels.usePointStyle = true;
    d.plugins.legend.labels.pointStyle = 'rectRounded';
    d.plugins.legend.labels.boxWidth = 8;
    d.plugins.legend.labels.boxHeight = 8;
    d.plugins.legend.labels.padding = 16;
    d.plugins.tooltip.backgroundColor = '#0E1C2B';
    d.plugins.tooltip.titleColor = '#fff';
    d.plugins.tooltip.bodyColor = 'rgba(255,255,255,.85)';
    d.plugins.tooltip.padding = 10;
    d.plugins.tooltip.cornerRadius = 8;
    d.plugins.tooltip.boxPadding = 4;
    d.plugins.tooltip.usePointStyle = true;
    d.elements.bar.borderRadius = 4;
    d.elements.line.borderWidth = 2;
    d.elements.point.radius = 0;
    d.elements.point.hoverRadius = 4;
    d.interaction.mode = 'index';
    d.interaction.intersect = false;
    d.scale.grid.drawTicks = false;
    d.scale.border = { display: false };
    d.scale.ticks.padding = 8;
    if (Chart.defaults.scales && Chart.defaults.scales.category) {
      Chart.defaults.scales.category.ticks.maxRotation = 0;
      Chart.defaults.scales.category.ticks.autoSkipPadding = 10;
    }
  }
  /** Position de légende adaptée à la largeur d'écran */
  C.legendPos = function (large) { return window.innerWidth < 768 ? 'bottom' : (large || 'right'); };

  // Tout tableau qui n'est pas déjà dans un conteneur défilant y est placé :
  // sur téléphone il défile horizontalement au lieu d'élargir la page.
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('table.table').forEach(function (t) {
      if (t.closest('.table-responsive')) return;
      var w = document.createElement('div');
      w.className = 'table-responsive';
      t.parentNode.insertBefore(w, t);
      w.appendChild(t);
    });
  });

  // --- Objectifs : jauges qui se remplissent et fête quand un palier est franchi ---
  // Chaque navigateur retient le plus haut palier déjà vu pour chaque restaurant et
  // chaque mois : l'animation ne se joue qu'une fois, puis sur clic du badge.
  document.documentElement.classList.add('hub-js');
  var calme = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function lire(cle) { try { return parseInt(localStorage.getItem('hub-goal:' + cle), 10) || 0; } catch (e) { return 0; } }
  function ecrire(cle, v) { try { localStorage.setItem('hub-goal:' + cle, String(v)); } catch (e) { /* navigation privée */ } }

  C.confettis = function () {
    if (calme) return;
    var cv = document.createElement('canvas');
    cv.className = 'goal-confetti';
    document.body.appendChild(cv);
    var ctx = cv.getContext('2d'), dpr = window.devicePixelRatio || 1;
    var W = cv.width = innerWidth * dpr, H = cv.height = innerHeight * dpr;
    var couleurs = ['#C8A84B', '#0F7B55', '#1F5FAD', '#E3A21A', '#22A06B', '#0E1C2B'];
    var p = [];
    for (var i = 0; i < 160; i++) {
      var depuisGauche = i % 2 === 0;
      p.push({
        x: depuisGauche ? 0 : W, y: H * .75,
        vx: (depuisGauche ? 1 : -1) * (4 + Math.random() * 9) * dpr, vy: -(10 + Math.random() * 11) * dpr,
        r: (4 + Math.random() * 5) * dpr, a: Math.random() * 6, va: (Math.random() - .5) * .4,
        c: couleurs[i % couleurs.length]
      });
    }
    var debut = performance.now();
    (function frame(t) {
      var age = t - debut;
      ctx.clearRect(0, 0, W, H);
      ctx.globalAlpha = age > 2400 ? Math.max(0, 1 - (age - 2400) / 800) : 1;
      p.forEach(function (q) {
        q.vy += .32 * dpr; q.vx *= .985; q.x += q.vx; q.y += q.vy; q.a += q.va;
        ctx.save(); ctx.translate(q.x, q.y); ctx.rotate(q.a);
        ctx.fillStyle = q.c; ctx.fillRect(-q.r / 2, -q.r / 4, q.r, q.r / 2);
        ctx.restore();
      });
      if (age < 3200) requestAnimationFrame(frame); else cv.remove();
    })(debut);
  };

  C.toast = function (html) {
    var t = document.createElement('div');
    t.className = 'goal-toast';
    t.setAttribute('role', 'status');
    t.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg><span></span>';
    t.querySelector('span').innerHTML = html;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 450); }, 5000);
  };

  function echapper(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  document.addEventListener('DOMContentLoaded', function () {
    var gagnes = [], franchis = [];
    document.querySelectorAll('[data-goal]').forEach(function (g) {
      var cle = g.getAttribute('data-goal-key'), palier = parseInt(g.getAttribute('data-goal-palier'), 10) || 0;
      if (palier > lire(cle)) {
        g.classList.add('is-new');
        (palier >= 100 ? gagnes : franchis).push({ nom: g.getAttribute('data-goal-name'), palier: palier });
        ecrire(cle, palier);
      }
      requestAnimationFrame(function () { requestAnimationFrame(function () { g.classList.add('is-ready'); }); });
    });
    if (gagnes.length) {
      setTimeout(C.confettis, 900);
      C.toast(gagnes.length === 1
        ? 'Bravo ! <strong>' + echapper(gagnes[0].nom) + '</strong> a ' + (gagnes[0].palier > 100 ? 'pulvérisé' : 'atteint') + ' son objectif du mois.'
        : 'Bravo ! Objectif du mois atteint pour <strong>' + gagnes.map(function (x) { return echapper(x.nom); }).join('</strong>, <strong>') + '</strong>.');
    } else if (franchis.length === 1) {
      C.toast('<strong>' + echapper(franchis[0].nom) + '</strong> a passé ' + franchis[0].palier + ' % de son objectif du mois.');
    } else if (franchis.length > 1) {
      C.toast(franchis.length + ' restaurants ont franchi un palier de leur objectif du mois.');
    }
  });

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-goal-replay]')) C.confettis();
  });

  // Recherche dans le sélecteur de société
  document.addEventListener('input', function (e) {
    if (!e.target.matches('[data-company-search]')) return;
    var q = e.target.value.trim().toLowerCase();
    var menu = e.target.closest('.dropdown-menu');
    var total = 0;
    menu.querySelectorAll('[data-company-group]').forEach(function (g) {
      var n = 0;
      g.querySelectorAll('[data-company-name]').forEach(function (a) {
        var ok = !q || a.getAttribute('data-company-name').indexOf(q) !== -1;
        a.style.display = ok ? '' : 'none';
        if (ok) n++;
      });
      g.style.display = n ? '' : 'none';
      total += n;
    });
    menu.querySelector('[data-company-empty]').style.display = total ? 'none' : 'block';
  });
  // Focus sur la recherche à l'ouverture ; Entrée ouvre la première société visible
  document.addEventListener('shown.bs.dropdown', function (e) {
    var input = e.target.parentNode.querySelector('[data-company-search]');
    if (input) input.focus();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || !e.target.matches('[data-company-search]')) return;
    e.preventDefault();
    var first = Array.prototype.find.call(e.target.closest('.dropdown-menu').querySelectorAll('[data-company-name]'), function (a) { return a.style.display !== 'none'; });
    if (first) window.location = first.href;
  });
})();
