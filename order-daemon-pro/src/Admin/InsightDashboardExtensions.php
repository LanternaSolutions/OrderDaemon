        // Register admin-post export handlers for premium users (WordPress way)
        add_action('admin_post_odcm_export_logs_csv', [$this, 'handle_export_csv']);
        add_action('admin_post_odcm_export_logs_json', [$this, 'handle_export_json']);
