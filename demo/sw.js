/*
A slow network for the loading-state demo, without slowing the server.

`php -S` serves one request at a time (and has no worker pool on Windows), so a
sleep() in the router would stall the whole case matrix behind it. Here the
browser waits instead: any /slow/… request is held 1.5–4 s, then fetched from
the same path without the /slow prefix. The router maps /slow/ too, so the page
still works, only fast, where no service worker runs.
*/

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()))

self.addEventListener('fetch', e => {
	const url = new URL(e.request.url)
	if (url.origin !== location.origin || !url.pathname.startsWith('/slow/')) return

	const real = url.pathname.slice('/slow'.length) + url.search
	const delay = 1500 + Math.random() * 2500
	e.respondWith(new Promise(r => setTimeout(r, delay)).then(() => fetch(real, { cache: 'no-store' })))
})
