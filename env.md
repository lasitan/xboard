# Xboard Admin Panel Variables / Configs

This document lists the runtime variables used by the admin frontend under `public/assets/admin`, and the configuration payload returned by the admin config API.

## 1. Runtime injected variables (`window.settings`)

Injected by: `resources/views/admin.blade.php`

The admin entry page sets:

- `window.settings.base_url`
  - Value: `"/"`
  - Usage: API base URL prefix used by the admin frontend.
- `window.settings.title`
  - Source: `admin_setting('app_name', 'XBoard')`
- `window.settings.version`
  - Source: `app(UpdateService::class)->getCurrentVersion()`
- `window.settings.logo`
  - Source: `admin_setting('logo')`
- `window.settings.secure_path`
  - Source: `admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))`
  - Notes: This is the admin panel path prefix.

## 2. Admin config API payload (V2)

Endpoint: `GET /{secure_path}/config/fetch`

Controller: `App\\Http\\Controllers\\V2\\Admin\\ConfigController::fetch()`

The API returns a JSON object with the following top-level groups.

### 2.1 `invite`

- `invite.invite_force`
  - Source: `admin_setting('invite_force', 0)`
  - Type: `bool`
- `invite.invite_commission`
  - Source: `admin_setting('invite_commission', 10)`
- `invite.invite_gen_limit`
  - Source: `admin_setting('invite_gen_limit', 5)`
- `invite.invite_never_expire`
  - Source: `admin_setting('invite_never_expire', 0)`
  - Type: `bool`
- `invite.commission_first_time_enable`
  - Source: `admin_setting('commission_first_time_enable', 1)`
  - Type: `bool`
- `invite.commission_auto_check_enable`
  - Source: `admin_setting('commission_auto_check_enable', 1)`
  - Type: `bool`
- `invite.commission_withdraw_limit`
  - Source: `admin_setting('commission_withdraw_limit', 100)`
- `invite.commission_withdraw_method`
  - Source: `admin_setting('commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT)`
- `invite.withdraw_close_enable`
  - Source: `admin_setting('withdraw_close_enable', 0)`
  - Type: `bool`
- `invite.commission_distribution_enable`
  - Source: `admin_setting('commission_distribution_enable', 0)`
  - Type: `bool`
- `invite.commission_distribution_l1`
  - Source: `admin_setting('commission_distribution_l1')`
- `invite.commission_distribution_l2`
  - Source: `admin_setting('commission_distribution_l2')`
- `invite.commission_distribution_l3`
  - Source: `admin_setting('commission_distribution_l3')`

### 2.2 `site`

- `site.logo`
  - Source: `admin_setting('logo')`
- `site.force_https`
  - Source: `admin_setting('force_https', 0)`
  - Type: `int`
- `site.stop_register`
  - Source: `admin_setting('stop_register', 0)`
  - Type: `int`
- `site.app_name`
  - Source: `admin_setting('app_name', 'XBoard')`
- `site.app_description`
  - Source: `admin_setting('app_description', 'XBoard is best!')`
- `site.app_url`
  - Source: `admin_setting('app_url')`
- `site.subscribe_url`
  - Source: `admin_setting('subscribe_url')`
- `site.try_out_plan_id`
  - Source: `admin_setting('try_out_plan_id', 0)`
  - Type: `int`
- `site.try_out_hour`
  - Source: `admin_setting('try_out_hour', 1)`
  - Type: `int`
- `site.tos_url`
  - Source: `admin_setting('tos_url')`
- `site.currency`
  - Source: `admin_setting('currency', 'CNY')`
- `site.currency_symbol`
  - Source: `admin_setting('currency_symbol', '¥')`

### 2.3 `subscribe`

- `subscribe.plan_change_enable`
  - Source: `admin_setting('plan_change_enable', 1)`
  - Type: `bool`
- `subscribe.reset_traffic_method`
  - Source: `admin_setting('reset_traffic_method', 0)`
  - Type: `int`
- `subscribe.surplus_enable`
  - Source: `admin_setting('surplus_enable', 1)`
  - Type: `bool`
- `subscribe.new_order_event_id`
  - Source: `admin_setting('new_order_event_id', 0)`
  - Type: `int`
- `subscribe.renew_order_event_id`
  - Source: `admin_setting('renew_order_event_id', 0)`
  - Type: `int`
- `subscribe.change_order_event_id`
  - Source: `admin_setting('change_order_event_id', 0)`
  - Type: `int`
- `subscribe.show_info_to_server_enable`
  - Source: `admin_setting('show_info_to_server_enable', 0)`
  - Type: `bool`
- `subscribe.show_protocol_to_server_enable`
  - Source: `admin_setting('show_protocol_to_server_enable', 0)`
  - Type: `bool`
- `subscribe.default_remind_expire`
  - Source: `admin_setting('default_remind_expire', 1)`
  - Type: `bool`
- `subscribe.default_remind_traffic`
  - Source: `admin_setting('default_remind_traffic', 1)`
  - Type: `bool`
- `subscribe.subscribe_path`
  - Source: `admin_setting('subscribe_path', 's')`

### 2.4 `frontend`

- `frontend.frontend_theme`
  - Source: `admin_setting('frontend_theme', 'Xboard')`
- `frontend.frontend_theme_sidebar`
  - Source: `admin_setting('frontend_theme_sidebar', 'light')`
- `frontend.frontend_theme_header`
  - Source: `admin_setting('frontend_theme_header', 'dark')`
- `frontend.frontend_theme_color`
  - Source: `admin_setting('frontend_theme_color', 'default')`
- `frontend.frontend_background_url`
  - Source: `admin_setting('frontend_background_url')`

### 2.5 `server`

- `server.server_token`
  - Source: `admin_setting('server_token')`
- `server.server_pull_interval`
  - Source: `admin_setting('server_pull_interval', 60)`
- `server.server_push_interval`
  - Source: `admin_setting('server_push_interval', 60)`
- `server.device_limit_mode`
  - Source: `admin_setting('device_limit_mode', 0)`
  - Type: `int`

### 2.6 `email`

- `email.email_template`
  - Source: `admin_setting('email_template', 'default')`
- `email.email_host`
  - Source: `admin_setting('email_host')`
- `email.email_port`
  - Source: `admin_setting('email_port')`
- `email.email_username`
  - Source: `admin_setting('email_username')`
- `email.email_password`
  - Source: `admin_setting('email_password')`
- `email.email_encryption`
  - Source: `admin_setting('email_encryption')`
- `email.email_from_address`
  - Source: `admin_setting('email_from_address')`
- `email.remind_mail_enable`
  - Source: `admin_setting('remind_mail_enable', false)`
  - Type: `bool`

### 2.7 `telegram`

- `telegram.telegram_bot_enable`
  - Source: `admin_setting('telegram_bot_enable', 0)`
  - Type: `bool`
- `telegram.telegram_bot_token`
  - Source: `admin_setting('telegram_bot_token')`
- `telegram.telegram_discuss_link`
  - Source: `admin_setting('telegram_discuss_link')`

### 2.8 `app`

- `app.windows_version`
  - Source: `admin_setting('windows_version', '')`
- `app.windows_download_url`
  - Source: `admin_setting('windows_download_url', '')`
- `app.macos_version`
  - Source: `admin_setting('macos_version', '')`
- `app.macos_download_url`
  - Source: `admin_setting('macos_download_url', '')`
- `app.android_version`
  - Source: `admin_setting('android_version', '')`
- `app.android_download_url`
  - Source: `admin_setting('android_download_url', '')`

### 2.9 `safe`

- `safe.email_verify`
  - Source: `admin_setting('email_verify', 0)`
  - Type: `bool`
- `safe.safe_mode_enable`
  - Source: `admin_setting('safe_mode_enable', 0)`
  - Type: `bool`
- `safe.secure_path`
  - Source: `admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))`
- `safe.email_whitelist_enable`
  - Source: `admin_setting('email_whitelist_enable', 0)`
  - Type: `bool`
- `safe.email_whitelist_suffix`
  - Source: `admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT)`
- `safe.email_gmail_limit_enable`
  - Source: `admin_setting('email_gmail_limit_enable', 0)`
  - Type: `bool`
- `safe.captcha_enable`
  - Source: `admin_setting('captcha_enable', 0)`
  - Type: `bool`
- `safe.captcha_type`
  - Source: `admin_setting('captcha_type', 'recaptcha')`
- `safe.recaptcha_key`
  - Source: `admin_setting('recaptcha_key', '')`
- `safe.recaptcha_site_key`
  - Source: `admin_setting('recaptcha_site_key', '')`
- `safe.recaptcha_v3_secret_key`
  - Source: `admin_setting('recaptcha_v3_secret_key', '')`
- `safe.recaptcha_v3_site_key`
  - Source: `admin_setting('recaptcha_v3_site_key', '')`
- `safe.recaptcha_v3_score_threshold`
  - Source: `admin_setting('recaptcha_v3_score_threshold', 0.5)`
- `safe.turnstile_secret_key`
  - Source: `admin_setting('turnstile_secret_key', '')`
- `safe.turnstile_site_key`
  - Source: `admin_setting('turnstile_site_key', '')`
- `safe.register_limit_by_ip_enable`
  - Source: `admin_setting('register_limit_by_ip_enable', 0)`
  - Type: `bool`
- `safe.register_limit_count`
  - Source: `admin_setting('register_limit_count', 3)`
- `safe.register_limit_expire`
  - Source: `admin_setting('register_limit_expire', 60)`
- `safe.password_limit_enable`
  - Source: `admin_setting('password_limit_enable', 1)`
  - Type: `bool`
- `safe.password_limit_count`
  - Source: `admin_setting('password_limit_count', 5)`
- `safe.password_limit_expire`
  - Source: `admin_setting('password_limit_expire', 60)`
- `safe.recaptcha_enable`
  - Source: `admin_setting('captcha_enable', 0)`
  - Type: `bool`
  - Notes: Backward compatible alias.

### 2.10 `subscribe_template`

- `subscribe_template.subscribe_template_singbox`
  - Source: `admin_setting('subscribe_template_singbox', defaultTemplate('singbox'))`
  - Notes: Returned formatted as pretty JSON if possible.
- `subscribe_template.subscribe_template_clash`
  - Source: `admin_setting('subscribe_template_clash', defaultTemplate('clash'))`
- `subscribe_template.subscribe_template_clashmeta`
  - Source: `admin_setting('subscribe_template_clashmeta', defaultTemplate('clashmeta'))`
- `subscribe_template.subscribe_template_stash`
  - Source: `admin_setting('subscribe_template_stash', defaultTemplate('stash'))`
- `subscribe_template.subscribe_template_surge`
  - Source: `admin_setting('subscribe_template_surge', defaultTemplate('surge'))`
- `subscribe_template.subscribe_template_surfboard`
  - Source: `admin_setting('subscribe_template_surfboard', defaultTemplate('surfboard'))`
