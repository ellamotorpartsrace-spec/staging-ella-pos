/**
 * views/shopee/shopee_alerts.js
 * Polling script to display Shopee alerts in real-time.
 */
document.addEventListener('DOMContentLoaded', () => {
    // Only run if EllaToast is available
    if (typeof EllaToast === 'undefined') {
        console.warn('Shopee Alerts: EllaToast is not loaded.');
        return;
    }

    let isPolling = false;

    async function pollShopeeAlerts() {
        if (isPolling) return;
        isPolling = true;

        try {
            const res = await fetch(`${window.BASE_URL || '/'}api/shopee/get_alerts.php`);
            const data = await res.json();

            if (data.success) {
                // Check token expiration first
                if (data.token_expired) {
                    if (window.shopeeAlertInterval) clearInterval(window.shopeeAlertInterval);
                    if (!window.location.href.includes('views/shopee/settings.php')) {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Shopee Token Expired',
                                text: 'Your Shopee connection token has expired. You must refresh it to continue syncing.',
                                confirmButtonText: 'Go to Settings',
                                confirmButtonColor: '#ee4d2d',
                                allowOutsideClick: false,
                                allowEscapeKey: false
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    window.location.href = `${window.BASE_URL || '/'}views/shopee/settings.php`;
                                }
                            });
                        } else {
                            alert('Your Shopee connection token has expired. Redirecting to Settings...');
                            window.location.href = `${window.BASE_URL || '/'}views/shopee/settings.php`;
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
                            EllaToast.error(`🚨 SHOPEE OOS ALERT: ${alert.message}`);
                        } else {
                            EllaToast.warning(`Shopee Alert: ${alert.message}`);
                        }
                    });

                    // Mark alerts as read
                    if (alertIds.length > 0) {
                        await fetch(`${window.BASE_URL || '/'}api/shopee/mark_alert_read.php`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ alert_ids: alertIds })
                        });
                    }
                }
            }
        } catch (e) {
            // Silently fail on network error so we don't annoy the user
            console.error('Shopee Alerts polling error:', e);
        } finally {
            isPolling = false;
        }
    }

    // Check immediately on load, then every 15 seconds
    pollShopeeAlerts();
    window.shopeeAlertInterval = setInterval(pollShopeeAlerts, 15000);
});
