// Lightweight debug flag resolver for gated logs
function odcmIsDebug() {
    try {
        const w = window || {};
        let v = (typeof w.ODCM_DEBUG !== 'undefined') ? w.ODCM_DEBUG : undefined;
        if (typeof v === 'undefined' && w.odcmInsightConfig && typeof w.odcmInsightConfig.debug !== 'undefined') {
            v = w.odcmInsightConfig.debug;
        }
        if (typeof v === 'undefined' && w.odcmRuleBuilderConfig && typeof w.odcmRuleBuilderConfig.debug !== 'undefined') {
            v = w.odcmRuleBuilderConfig.debug;
        }
        if (typeof v === 'string') {
            return v.toLowerCase() === 'true';
        }
        if (v && typeof v === 'object') {
            if (Object.prototype.hasOwnProperty.call(v, 'enabled')) {
                return !!v.enabled;
            }
        }
        return !!v;
    } catch (e) { return false; }
}

document.addEventListener('DOMContentLoaded', function() {
    if (odcmIsDebug()) { console.log('ODCM Admin Notices: Script loaded'); }
    
    // We use event delegation to handle multiple notices
    document.addEventListener('click', function(e) {
        // Check if a dismiss button inside our specific notice was clicked
        if (e.target && e.target.matches('.odcm-site-wide-notice .notice-dismiss')) {
            if (odcmIsDebug()) { console.log('ODCM Admin Notices: Dismiss button clicked'); }
            
            const noticeDiv = e.target.closest('.odcm-site-wide-notice');
            const noticeId = e.target.getAttribute('data-notice-id');
            const nonce = e.target.getAttribute('data-nonce');
            
            if (odcmIsDebug()) { console.log('ODCM Admin Notices: Notice ID:', noticeId); }
            if (odcmIsDebug()) { console.log('ODCM Admin Notices: Nonce:', nonce); }

            if (!noticeId || !nonce) {
                console.error('ODCM Admin Notices: Missing notice ID or nonce');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'odcm_dismiss_site_wide_notice');
            formData.append('nonce', nonce);
            formData.append('notice_id', noticeId);

            // Hide the notice immediately for a good UX
            noticeDiv.style.transition = 'opacity 0.5s';
            noticeDiv.style.opacity = '0';
            setTimeout(() => noticeDiv.remove(), 500);

            // Use the properly localized AJAX URL, with fallback to global ajaxurl
            const ajaxUrl = (typeof odcm_ajax !== 'undefined' && odcm_ajax.ajaxurl) 
                ? odcm_ajax.ajaxurl 
                : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
            
            if (odcmIsDebug()) { console.log('ODCM Admin Notices: Using AJAX URL:', ajaxUrl); }

            // Send the request to the server to delete the transient
            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            }).then(response => {
                if (odcmIsDebug()) { console.log('ODCM Admin Notices: Response status:', response.status); }
                if (!response.ok) {
                    console.error('ODCM Admin Notices: Failed to dismiss notice:', response.statusText);
                    // Show the notice again if the request failed
                    noticeDiv.style.opacity = '1';
                } else {
                    if (odcmIsDebug()) { console.log('ODCM Admin Notices: Notice dismissed successfully'); }
                    return response.json();
                }
            }).then(data => {
                if (data) {
                    if (odcmIsDebug()) { console.log('ODCM Admin Notices: Server response:', data); }
                }
            }).catch(error => {
                console.error('ODCM Admin Notices: Error dismissing notice:', error);
                // Show the notice again if the request failed
                noticeDiv.style.opacity = '1';
            });
        }
    });
});
