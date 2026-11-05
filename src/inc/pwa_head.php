<?php
/**
 * Balises pour installer AD'Hub comme une appli sur iPhone et Android
 * (écran d'accueil, plein écran sans barre du navigateur).
 * Inclus dans le <head> des gabarits.
 */
?>
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#0E1C2B">
  <meta name="application-name" content="AD'Hub">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="AD'Hub">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <script>if ('serviceWorker' in navigator) { window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); }); }</script>
