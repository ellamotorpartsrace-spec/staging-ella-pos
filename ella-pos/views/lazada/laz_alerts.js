/**
 * views/lazada/laz_alerts.js
 * Polling script to display Lazada alerts in real-time.
 */
document.addEventListener('DOMContentLoaded', () => {
    // Only run if EllaToast is available
    if (typeof EllaToast === 'undefined') {
        console.warn('Lazada Alerts: EllaToast is not loaded.');
        return;
    }

    let isPolling = false;

    async function pollLazadaAlerts() {
        if (isPolling) return;
        isPolling = true;

        try {
            const res = await fetch(`${window.BASE_URL || '/'}api/lazada/get_alerts.php`);
            const data = await res.json();

            if (data.success) {
                // Check token expiration first
                if (data.token_expired) {
                    if (window.lazadaAlertInterval) clearInterval(window.lazadaAlertInterval);
                    if (!window.location.href.includes('views/lazada/laz_settings.php')) {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Lazada Token Expired',
                                text: 'Your Lazada connection token has expired. You must refresh it to continue syncing.',
                                confirmButtonText: 'Go to Settings',
                                confirmButtonColor: '#ee4d2d',
                                allowOutsideClick: false,
                                allowEscapeKey: false
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    window.location.href = `${window.BASE_URL || '/'}views/lazada/laz_settings.php`;
                                }
                            });
                        } else {
                            alert('Your Lazada connection token has expired. Redirecting to Settings...');
                            window.location.href = `${window.BASE_URL || '/'}views/lazada/laz_settings.php`;
                        }
                    }
                    return; // Stop processing further alerts
                }

                if (data.alerts && data.alerts.length > 0) {
                    const alertIds = [];
                    
                    data.alerts.forEach(alert => {
                        alertIds.push(alert.id);
                        // Display the toast
                        if (alert.alert_type === 'out_of_stock') {
                            EllaToast.error(`🚨 LAZADA OOS ALERT: ${alert.message}`);
                        } else {
                            EllaToast.warning(`Lazada Alert: ${alert.message}`);
                        }
                    });

                    // Mark alerts as read
                    if (alertIds.length > 0) {
                        await fetch(`${window.BASE_URL || '/'}api/lazada/mark_alert_read.php`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ alert_ids: alertIds })
                        });
                    }
                }
            }
        } catch (e) {
            // Silently fail on network error so we don't annoy the user
            console.error('Lazada Alerts polling error:', e);
        } finally {
            isPolling = false;
        }
    }

    // Check immediately on load, then every 15 seconds
    pollLazadaAlerts();
    window.lazadaAlertInterval = setInterval(pollLazadaAlerts, 15000);
});

