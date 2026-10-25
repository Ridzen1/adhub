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
