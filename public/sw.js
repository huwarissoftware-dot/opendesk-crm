// OpenDesk CRM service worker - caches the app shell only; data always comes from the server.
self.addEventListener('install', function(e){ self.skipWaiting(); });
self.addEventListener('fetch', function(e){ /* network-first: CRM data must always be live */ });
