/**
 * ============================================================
 * ELLA CONFIRM — Styled Confirmation Dialogs
 * ============================================================
 * Usage:
 *   EllaConfirm.show({
 *       title: 'Clear Cart?',
 *       message: 'All items will be removed from your cart.',
 *       confirmText: 'Clear',
 *       confirmClass: 'btn-danger',
 *       onConfirm: () => { ... }
 *   });
 *
 *   // Or with async/await:
 *   const confirmed = await EllaConfirm.ask('Delete this draft?');
 *   if (confirmed) { ... }
 */

const EllaConfirm = (() => {
    /**
     * Show a confirmation dialog
     * @param {Object} options
     * @param {string} options.title
     * @param {string} options.message
     * @param {string} options.confirmText - Button text (default: "Confirm")
     * @param {string} options.cancelText  - Button text (default: "Cancel")
     * @param {string} options.confirmClass - Button class (default: "btn-danger")
     * @param {string} options.icon - Icon class (default: "fa-question-circle")
     * @param {string} options.iconColor - Icon color class (default: "text-warning")
     * @param {Function} options.onConfirm - Called when confirmed
     * @param {Function} options.onCancel  - Called when cancelled
     * @param {boolean} options.isHtml - Whether message is HTML (default: false)
     * @param {string} options.modalSize - Modal size class e.g. 'modal-md', 'modal-lg' (default: 'modal-sm')
     * @returns {Promise<boolean>}
     */
    function show(options = {}) {
        return new Promise((resolve) => {
            const {
                title = 'Are you sure?',
                message = 'This action cannot be undone.',
                confirmText = 'Confirm',
                cancelText = 'Cancel',
                confirmClass = 'btn-danger',
                icon = 'fa-question-circle',
                iconColor = 'text-warning',
                isHtml = false,
                modalSize = 'modal-sm',
                onConfirm = null,
                onCancel = null
            } = options;

            // Create fresh DOM element
            const modalEl = document.createElement('div');
            modalEl.className = 'modal fade';
            modalEl.setAttribute('tabindex', '-1');
            modalEl.setAttribute('aria-hidden', 'true');
            modalEl.innerHTML = `
                <div class="modal-dialog modal-dialog-centered ${modalSize}">
                    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                        <div class="modal-body text-center p-4">
                            <div class="mb-3">
                                <i class="fa-solid ${icon} fa-3x ${iconColor}"></i>
                            </div>
                            <h5 class="fw-bold mb-2">${title}</h5>
                            <p class="text-muted small mb-0">${isHtml ? message : ''}</p>
                        </div>
                        <div class="modal-footer border-0 justify-content-center gap-2 pb-4 pt-0">
                            <button type="button" class="btn btn-outline-secondary px-4 btn-cancel" data-bs-dismiss="modal">
                                ${cancelText}
                            </button>
                            <button type="button" class="btn ${confirmClass} px-4 btn-confirm">
                                ${confirmText}
                            </button>
                        </div>
                    </div>
                </div>
            `;

            if (!isHtml) {
                modalEl.querySelector('p').textContent = message;
            }

            document.body.appendChild(modalEl);
            const bsModal = new bootstrap.Modal(modalEl);

            let resolved = false;

            modalEl.querySelector('.btn-confirm').addEventListener('click', () => {
                resolved = true;
                if (onConfirm) onConfirm();
                bsModal.hide();
            });

            modalEl.querySelector('.btn-cancel').addEventListener('click', () => {
                resolved = false;
                if (onCancel) onCancel();
                bsModal.hide();
            });

            modalEl.addEventListener('hidden.bs.modal', () => {
                resolve(resolved);
                modalEl.remove();
            });

            bsModal.show();
        });
    }

    /**
     * Shorthand for simple yes/no question
     * @param {string} message
     * @returns {Promise<boolean>}
     */
    function ask(message, title) {
        return show({ title: title || 'Confirm', message });
    }

    return { show, ask };
})();
