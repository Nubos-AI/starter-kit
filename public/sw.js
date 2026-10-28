self.addEventListener('push', (event) => {
    const payload = event.data ? event.data.json() : {};

    event.waitUntil(
        self.registration.showNotification(payload.title, {
            body: payload.body,
            data: { url: payload.data && payload.data.url },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data && event.notification.data.url;

    if (url) {
        event.waitUntil(clients.openWindow(url));
    }
});
