(function($){
    'use strict';

    function getContextFromTarget(target){
        try {
            if ($(target).closest('.odcm-premium-filter-group').length){
                return 'insight_filters';
            }
            // default to rule builder if badges or premium option areas
            return 'rule_builder';
        } catch(e){ return 'rule_builder'; }
    }

    function isDismissed(promptKey){
        try {
            var prefs = window.odcmUpgradePrompts && window.odcmUpgradePrompts.prefs;
            return !!(prefs && prefs.dismissed && prefs.dismissed[promptKey]);
        } catch(e){ return false; }
    }

    function frequencyAllows(){
        try {
            var prefs = window.odcmUpgradePrompts && window.odcmUpgradePrompts.prefs;
            var freq = prefs && prefs.frequency ? prefs.frequency : 'normal';
            if (freq === 'off') return false;
            // For 'reduced', we could throttle further; for now allow but rely on dismissal
            return true;
        } catch(e){ return true; }
    }

    function openModal(ctx){
        var cfg = window.odcmUpgradePrompts || {};
        if (!cfg.enabled) return;
        var ctxCfg = (cfg.contexts && cfg.contexts[ctx]) ? cfg.contexts[ctx] : cfg.contexts['rule_builder'];
        if (!ctxCfg) return;
        if (isDismissed(ctxCfg.promptKey)) return;
        if (!frequencyAllows()) return;

        var $modal = $('#odcm-upgrade-modal');
        if (!$modal.length) return;

        // Title and message
        $modal.find('#odcm-upgrade-modal-title').text(ctxCfg.title || '');
        $modal.find('.odcm-upgrade-modal__message').text(ctxCfg.message || '');

        // Comparison table
        var $cmp = $modal.find('.odcm-upgrade-modal__comparison');
        $cmp.empty();
        var comp = Array.isArray(cfg.comparison) ? cfg.comparison : [];
        if (comp.length){
            var $table = $('<table class="odcm-upgrade-compare" />');
            $table.append('<thead><tr><th>'+escHtml('Feature')+'</th><th>'+escHtml('Core')+'</th><th>'+escHtml('Pro')+'</th></tr></thead>');
            var $tb = $('<tbody/>');
            comp.forEach(function(row){
                var f = escHtml(row.feature || '');
                var c = escHtml(row.core || '');
                var p = escHtml(row.pro || '');
                $tb.append('<tr><td>'+f+'</td><td>'+c+'</td><td>'+p+'</td></tr>');
            });
            $table.append($tb);
            $cmp.append($table);
        }

        // Links
        var docsUrl = cfg.docsUrl || cfg.websiteUrl || '';
        var $learn = $modal.find('.odcm-upgrade-modal__learn-more');
        if (docsUrl){
            $learn.attr('href', docsUrl).show().text((cfg.i18n && cfg.i18n.learnMore) || 'Learn more');
            $modal.find('.odcm-upgrade-link--docs').attr('href', docsUrl).text((cfg.i18n && cfg.i18n.learnMore) || 'Learn more').show();
        } else {
            $learn.hide();
            $modal.find('.odcm-upgrade-link').hide();
        }

        // Reset checkbox
        $modal.find('.odcm-upgrade-modal__dont-show').prop('checked', false).data('promptKey', ctxCfg.promptKey);

        // Show modal
        $modal.attr('aria-hidden', 'false').fadeIn(120);

        // Close logic
        function close(){
            $modal.attr('aria-hidden', 'true').fadeOut(120);
        }
        $modal.find('.odcm-upgrade-modal__close, .odcm-upgrade-modal__close-btn, .odcm-upgrade-modal__backdrop').off('click').on('click', function(){
            // If user checked Don't show again, persist dismissal
            var dont = $modal.find('.odcm-upgrade-modal__dont-show').is(':checked');
            var key = $modal.find('.odcm-upgrade-modal__dont-show').data('promptKey');
            if (dont && key){
                dismissPrompt(key);
            }
            close();
        });
    }

    function dismissPrompt(promptKey){
        var cfg = window.odcmUpgradePrompts || {};
        if (!cfg.ajaxUrl || !cfg.nonce) return;
        $.post(cfg.ajaxUrl, {
            action: 'odcm_dismiss_prompt',
            nonce: cfg.nonce,
            promptKey: String(promptKey || '')
        });
        try {
            // update local state
            if (cfg.prefs && cfg.prefs.dismissed){
                cfg.prefs.dismissed[promptKey] = true;
            }
        } catch(e){}
    }

    function escHtml(str){
        return String(str || '').replace(/[&<>\"]+/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c] || c;
        });
    }

    // Frequency settings UI handler (optional if a control is present)
    $(document).on('change', '.odcm-upgrade-frequency', function(){
        var cfg = window.odcmUpgradePrompts || {};
        if (!cfg.ajaxUrl || !cfg.nonce) return;
        var val = $(this).val();
        $.post(cfg.ajaxUrl, {
            action: 'odcm_update_upgrade_prefs',
            nonce: cfg.nonce,
            frequency: String(val || 'normal')
        }).done(function(){
            if (cfg.prefs){ cfg.prefs.frequency = String(val || 'normal'); }
        });
    });

    // Click triggers: overlays and badges
    $(document).on('click', '.odcm-premium-overlay, .odcm-premium-badge', function(e){
        try {
            // Only intercept when upgrade prompts enabled
            var cfg = window.odcmUpgradePrompts || {};
            if (!cfg.enabled) return;
            e.preventDefault();
            e.stopPropagation();
            var ctx = getContextFromTarget(e.currentTarget);
            openModal(ctx);
        } catch(err){}
    });

})(jQuery);
