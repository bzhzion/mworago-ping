<?php
/**
 * Plugin Name: Mworago Ping
 * Description: Extension de WordPress Popular Posts — comptabilise les lectures depuis l'app Mworago directement dans les tables WPP.
 * Version:     2.4.1
 * Author:      Breizhzion
 * Author URI: https://breizhzion.com
 *
 * Requiert : WordPress Popular Posts (tables wp_popularpostsdata + wp_popularpostssummary)
 * Config    : WP Admin > Réglages > Kpopify Ping
 * Usage     : POST /wp-json/kpopify/v1/ping   Header: X-App-Token: <token>   Body: { "post_id": 123 }
 */

defined('ABSPATH') || exit;

define('KPOPIFY_PING_BURST_KEY', 'kpopify_ping_burst');

// Token partagé app ↔ plugin — stocké en SHA-256 dans wp_options.
// Peut être surchargé via WP Admin > Réglages > Kpopify Ping.
// Le token en clair est dans l'app mobile (sécurité par obscurcissement — voir pingService.ts).
define('KPOPIFY_PING_DEFAULT_TOKEN_HASH', hash('sha256', 'kpopify-app-v2-ping-2026'));

function kpopify_ping_burst_max():      int { return max(1,  (int) get_option('kpopify_ping_burst_max',     600)); }
function kpopify_ping_burst_window():   int { return max(1,  (int) get_option('kpopify_ping_burst_window',   60)); }
function kpopify_ping_ip_burst_max():   int { return max(1,  (int) get_option('kpopify_ping_ip_burst_max',   20)); }
function kpopify_ping_cooldown():       int { return max(1,  (int) get_option('kpopify_ping_cooldown',    86400)); }
function kpopify_ping_ip_max():         int { return max(1,  (int) get_option('kpopify_ping_ip_max',          1)); }

function kpopify_ping_wpp_active(): bool {
    global $wpdb;
    $t = $wpdb->prefix . 'popularpostsdata';
    return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($t))) === $t;
}

/**
 * Incrémente un compteur de manière atomique via MySQL et retourne la nouvelle valeur.
 * Évite la race condition du couple get_transient / set_transient.
 */
function kpopify_ping_atomic_incr(string $key, int $window): int {
    global $wpdb;
    $opt  = '_transient_' . $key;
    $topt = '_transient_timeout_' . $key;
    $now  = time();

    $expires = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $topt
    ));
    if (!$expires || $expires <= $now) {
        delete_transient($key);
        set_transient($key, 1, $window);
        return 1;
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s",
        $opt
    ));

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt
    ));
}

// ── Alerte admin si WPP absent ────────────────────────────────────────────────

add_action('admin_notices', function () {
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, ['settings_page_kpopify-ping', 'plugins'], true)) return;
    if (!kpopify_ping_wpp_active()) {
        echo '<div class="notice notice-error"><p>'
           . '<strong>Kpopify Ping :</strong> '
           . 'WordPress Popular Posts est absent ou désactivé. '
           . '<strong>Aucun ping ne sera enregistré</strong> tant que WPP n\'est pas actif.'
           . '</p></div>';
    }
});

// ── Endpoint REST ─────────────────────────────────────────────────────────────

add_action('rest_api_init', function () {
    register_rest_route('kpopify/v1', '/ping', [
        'methods'             => 'POST',
        'callback'            => 'kpopify_ping_handle',
        'permission_callback' => 'kpopify_ping_verify_token',
        'args' => [
            'post_id' => [
                'required'          => true,
                'validate_callback' => fn($v) => is_numeric($v) && (int)$v > 0,
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
});

/**
 * Vérifie le header X-App-Token en temps constant (SHA-256).
 * Token configuré via WP Admin, sinon hash par défaut du token hardcodé dans l'app.
 */
function kpopify_ping_verify_token(WP_REST_Request $request): bool {
    $token    = (string) ($request->get_header('X-App-Token') ?? '');
    $expected = (string) get_option('kpopify_ping_app_token_hash', KPOPIFY_PING_DEFAULT_TOKEN_HASH);
    return hash_equals($expected, hash('sha256', $token));
}

function kpopify_ping_handle(WP_REST_Request $request): WP_REST_Response {
    if (!kpopify_ping_wpp_active()) {
        return new WP_REST_Response(['error' => 'wpp_not_active'], 503);
    }

    $post_id  = $request->get_param('post_id');
    $ip_hash  = md5(kpopify_ping_get_ip());
    $window   = kpopify_ping_burst_window();

    // IDOR mineur : sans ce contrôle, un appelant peut "réchauffer" un post_id
    // arbitraire (brouillon, VIP non encore publié, ID inexistant) avant que
    // l'article ne soit réellement lu par personne. On n'accepte que les
    // articles publiés (les posts `private` sont le modèle VIP intentionnel,
    // ils restent comptabilisables une fois "publiés" au sens WP).
    $post_status = get_post_status($post_id);
    if ($post_status !== 'publish' && $post_status !== 'private') {
        return new WP_REST_Response(['ok' => false, 'reason' => 'invalid_post'], 200);
    }
    if (get_post_type($post_id) !== 'post') {
        return new WP_REST_Response(['ok' => false, 'reason' => 'invalid_post'], 200);
    }

    // --- burst par IP ---
    $ip_burst_key = 'kpopify_ping_burst_ip_' . $ip_hash;
    $ip_burst     = kpopify_ping_atomic_incr($ip_burst_key, $window);
    if ($ip_burst > kpopify_ping_ip_burst_max()) {
        return new WP_REST_Response(['error' => 'too_many_requests'], 503);
    }

    // --- burst global ---
    $global_burst = kpopify_ping_atomic_incr(KPOPIFY_PING_BURST_KEY, $window);
    if ($global_burst > kpopify_ping_burst_max()) {
        return new WP_REST_Response(['error' => 'too_many_requests'], 503);
    }

    // --- cooldown par article + IP ---
    $cooldown_key = 'kpopify_ping_' . $post_id . '_' . $ip_hash;
    $views        = kpopify_ping_atomic_incr($cooldown_key, kpopify_ping_cooldown());

    if ($views > kpopify_ping_ip_max()) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = option_value - 1 WHERE option_name = %s",
            '_transient_' . $cooldown_key
        ));
        return new WP_REST_Response(['ok' => false, 'reason' => 'cooldown'], 200);
    }

    // --- écriture dans les tables WPP ---
    global $wpdb;

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}popularpostsdata (postid, day, last_viewed, pageviews)
         VALUES (%d, NOW(), NOW(), 1)
         ON DUPLICATE KEY UPDATE pageviews = pageviews + 1, last_viewed = NOW()",
        $post_id
    ));

    $wpdb->insert(
        $wpdb->prefix . 'popularpostssummary',
        [
            'postid'        => $post_id,
            'pageviews'     => 1,
            'view_date'     => current_time('Y-m-d'),
            'view_datetime' => current_time('mysql'),
        ],
        ['%d', '%d', '%s', '%s']
    );

    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT pageviews FROM {$wpdb->prefix}popularpostsdata WHERE postid = %d",
        $post_id
    ));

    return new WP_REST_Response(['ok' => true, 'views' => $total], 200);
}

/**
 * Retourne l'IP réelle du client.
 * CF-Connecting-IP est fiable uniquement si REMOTE_ADDR est une IP Cloudflare.
 * X-Forwarded-For est contrôlé par le client → non utilisé pour le rate-limit.
 */
function kpopify_ping_get_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && kpopify_ping_is_cloudflare_ip($remote)) {
        return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    return $remote;
}

function kpopify_ping_is_cloudflare_ip(string $ip): bool {
    static $cf_ranges = [
        '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '104.16.0.0/13',   '104.24.0.0/14',   '108.162.192.0/18',
        '131.0.72.0/22',   '141.101.64.0/18',  '162.158.0.0/15',
        '172.64.0.0/13',   '173.245.48.0/20',  '188.114.96.0/20',
        '190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17',
    ];
    $long = ip2long($ip);
    if ($long === false) return false;
    foreach ($cf_ranges as $cidr) {
        [$net, $bits] = explode('/', $cidr);
        $shift = 32 - (int)$bits;
        if (($long >> $shift) === (ip2long($net) >> $shift)) return true;
    }
    return false;
}

// ── Page de configuration ─────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_options_page('Kpopify Ping', 'Kpopify Ping', 'manage_options', 'kpopify-ping', 'kpopify_ping_settings_page');
});

add_action('admin_init', function () {
    register_setting('kpopify_ping', 'kpopify_ping_app_token_hash', ['type' => 'string', 'sanitize_callback' => 'kpopify_ping_sanitize_token', 'default' => '']);
    register_setting('kpopify_ping', 'kpopify_ping_burst_max',    ['type' => 'integer', 'sanitize_callback' => fn($v) => max(1,   (int)$v), 'default' => 600]);
    register_setting('kpopify_ping', 'kpopify_ping_burst_window', ['type' => 'integer', 'sanitize_callback' => fn($v) => max(1,   (int)$v), 'default' => 60]);
    register_setting('kpopify_ping', 'kpopify_ping_ip_burst_max', ['type' => 'integer', 'sanitize_callback' => fn($v) => max(1,   (int)$v), 'default' => 20]);
    register_setting('kpopify_ping', 'kpopify_ping_cooldown',     ['type' => 'integer', 'sanitize_callback' => fn($v) => max(60,  (int)$v), 'default' => 86400]);
    register_setting('kpopify_ping', 'kpopify_ping_ip_max',       ['type' => 'integer', 'sanitize_callback' => fn($v) => max(1,   (int)$v), 'default' => 1]);
});

function kpopify_ping_sanitize_token(string $raw): string {
    $raw = sanitize_text_field($raw);
    if (empty($raw)) return (string) get_option('kpopify_ping_app_token_hash', '');
    if (strlen($raw) < 16) {
        add_settings_error('kpopify_ping', 'token_short', 'Le token doit faire au moins 16 caractères.', 'error');
        return (string) get_option('kpopify_ping_app_token_hash', '');
    }
    return hash('sha256', $raw);
}

add_action('admin_enqueue_scripts', function (string $hook) {
    if ($hook !== 'settings_page_kpopify-ping') return;
    wp_enqueue_script('kpopify-ping-admin', plugin_dir_url(__FILE__) . 'admin.js', [], '2.3.0', true);
});

add_action('admin_post_kpopify_ping_reset_burst', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('kpopify_ping_reset_burst');
    delete_transient(KPOPIFY_PING_BURST_KEY);
    wp_redirect(add_query_arg(['page' => 'kpopify-ping', 'burst_reset' => '1'], admin_url('options-general.php')));
    exit;
});

function kpopify_ping_settings_page(): void {
    if (!current_user_can('manage_options')) wp_die('Forbidden');

    $wpp_active   = kpopify_ping_wpp_active();
    $wpp_url      = admin_url('admin.php?page=wpp-settings');
    $has_token    = !empty(get_option('kpopify_ping_app_token_hash', ''));
    $top_articles = [];

    if ($wpp_active) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- pas de variable utilisateur
        $top_articles = $wpdb->get_results(
            "SELECT p.ID, p.post_title, pd.pageviews
             FROM {$wpdb->prefix}popularpostsdata pd
             JOIN {$wpdb->posts} p ON p.ID = pd.postid
             WHERE p.post_status = 'publish'
             ORDER BY pd.pageviews DESC
             LIMIT 10"
        );
    }
    ?>
    <div class="wrap">
        <h1>Kpopify Ping</h1>

        <?php settings_errors('kpopify_ping'); ?>
        <?php if (isset($_GET['settings-updated'])): ?><div class="notice notice-success is-dismissible"><p>✓ Paramètres enregistrés.</p></div><?php endif; ?>
        <?php if (isset($_GET['burst_reset'])): ?><div class="notice notice-success is-dismissible"><p>✓ Verrou burst réinitialisé.</p></div><?php endif; ?>

        <h2>Statut</h2>
        <table class="widefat striped" style="max-width:560px;margin-bottom:16px">
            <tbody>
                <tr>
                    <td style="width:200px"><strong>WordPress Popular Posts</strong></td>
                    <td><?php if ($wpp_active): ?>
                        <span style="color:#46b450">✓ Actif</span>
                        &nbsp;—&nbsp;<a href="<?php echo esc_url($wpp_url); ?>">Réglages WPP →</a>
                    <?php else: ?>
                        <span style="color:#dc3232">✗ Absent — les pings sont rejetés (503)</span>
                    <?php endif; ?></td>
                </tr>
                <tr>
                    <td><strong>Token app</strong></td>
                    <td><?php if ($has_token): ?>
                        <span style="color:#46b450">✓ Token personnalisé actif</span>
                    <?php else: ?>
                        <span style="color:#f0ad4e">⚠ Token par défaut (hardcodé) — configurer ci-dessous pour plus de sécurité</span>
                    <?php endif; ?></td>
                </tr>
                <tr>
                    <td><strong>Endpoint</strong></td>
                    <td><code><?php echo esc_html(home_url('/wp-json/kpopify/v1/ping')); ?></code></td>
                </tr>
            </tbody>
        </table>

        <?php if ($wpp_active): ?>
        <h2>Top articles (vues totales WPP)</h2>
        <p style="color:#666;font-size:13px">Ces chiffres incluent les vues web <em>et</em> les lectures app — c'est la même table que WPP utilise.</p>
        <?php if ($top_articles): ?>
        <table class="widefat striped" style="max-width:700px;margin-top:8px">
            <thead><tr><th>#</th><th>Article</th><th style="text-align:right">Vues totales</th></tr></thead>
            <tbody>
            <?php foreach ($top_articles as $i => $row): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><a href="<?php echo esc_url(get_edit_post_link($row->ID)); ?>"><?php echo esc_html($row->post_title); ?></a></td>
                    <td style="text-align:right"><strong><?php echo number_format((int)$row->pageviews); ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?><p style="color:#888">Aucune vue enregistrée pour l'instant.</p><?php endif; ?>
        <?php endif; ?>

        <hr style="margin:24px 0">

        <h2>Token d'authentification app</h2>
        <p style="color:#666;font-size:13px">
            L'app mobile envoie ce token dans le header <code>X-App-Token</code> à chaque ping.<br>
            Le token est stocké en SHA-256 — il ne peut pas être relu après enregistrement.<br>
            Si laissé vide, le token par défaut hardcodé dans le plugin est utilisé.
        </p>
        <form method="post" action="options.php">
            <?php settings_fields('kpopify_ping'); ?>
            <table class="form-table" role="presentation">

                <tr>
                    <th><label for="kpopify_ping_app_token_hash">Nouveau token app</label></th>
                    <td>
                        <input type="password" id="kpopify_ping_app_token_hash" name="kpopify_ping_app_token_hash"
                               value="" class="regular-text code" autocomplete="new-password"
                               placeholder="Laisser vide pour conserver le token actuel" />
                        <p class="description">Minimum 16 caractères. Laisser vide pour ne pas modifier.</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="kpopify_ping_ip_burst_max">Burst max par IP</label></th>
                    <td>
                        <input type="number" id="kpopify_ping_ip_burst_max" name="kpopify_ping_ip_burst_max"
                               value="<?php echo esc_attr(kpopify_ping_ip_burst_max()); ?>"
                               min="1" max="500" class="small-text" />
                        <p class="description">Max requêtes par IP sur la fenêtre burst. Défaut : 20.</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="kpopify_ping_burst_max">Burst max global</label></th>
                    <td>
                        <input type="number" id="kpopify_ping_burst_max" name="kpopify_ping_burst_max"
                               value="<?php echo esc_attr(kpopify_ping_burst_max()); ?>"
                               min="1" max="10000" class="small-text" />
                        <p class="description">Max requêtes toutes IPs confondues sur la fenêtre burst. Défaut : 600.</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="kpopify_ping_burst_window">Fenêtre burst (secondes)</label></th>
                    <td>
                        <input type="number" id="kpopify_ping_burst_window" name="kpopify_ping_burst_window"
                               value="<?php echo esc_attr(kpopify_ping_burst_window()); ?>"
                               min="1" max="3600" class="small-text" />
                        <p class="description">Durée commune aux deux compteurs burst. Défaut : 60 s.</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="kpopify_ping_cooldown">Cooldown article/IP (secondes)</label></th>
                    <td>
                        <input type="number" id="kpopify_ping_cooldown" name="kpopify_ping_cooldown"
                               value="<?php echo esc_attr(kpopify_ping_cooldown()); ?>"
                               min="60" max="604800" class="small-text" />
                        <span style="margin-left:8px">
                            <button type="button" class="button button-small kpopify-preset" data-target="kpopify_ping_cooldown" data-value="21600">6 h</button>
                            <button type="button" class="button button-small kpopify-preset" data-target="kpopify_ping_cooldown" data-value="43200">12 h</button>
                            <button type="button" class="button button-small kpopify-preset" data-target="kpopify_ping_cooldown" data-value="86400">24 h</button>
                            <button type="button" class="button button-small kpopify-preset" data-target="kpopify_ping_cooldown" data-value="172800">48 h</button>
                            <button type="button" class="button button-small kpopify-preset" data-target="kpopify_ping_cooldown" data-value="604800">7 j</button>
                        </span>
                        <p class="description">Fenêtre de déduplication par (article + IP). Défaut : 86400 (24 h).</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="kpopify_ping_ip_max">Vues max par IP / article</label></th>
                    <td>
                        <input type="number" id="kpopify_ping_ip_max" name="kpopify_ping_ip_max"
                               value="<?php echo esc_attr(kpopify_ping_ip_max()); ?>"
                               min="1" max="100" class="small-text" />
                        <span style="margin-left:8px">
                            <button type="button" class="button button-small kpopify-preset" data-target="kpopify_ping_ip_max" data-value="1">1×</button>
                            <button type="button" class="button button-small kpopify-preset" data-target="kpopify_ping_ip_max" data-value="2">2×</button>
                            <button type="button" class="button button-small kpopify-preset" data-target="kpopify_ping_ip_max" data-value="3">3×</button>
                        </span>
                        <p class="description">Nombre de vues comptabilisées pour la même IP sur le même article pendant la fenêtre cooldown. Défaut : 1.</p>
                    </td>
                </tr>

            </table>
            <?php submit_button('Enregistrer les paramètres'); ?>
        </form>

        <hr style="margin:24px 0">

        <h2>Déblocage burst</h2>
        <p>Si le verrou burst bloque toutes les requêtes, le réinitialiser ici. <strong>Ne touche pas aux données WPP.</strong></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="kpopify_ping_reset_burst" />
            <?php wp_nonce_field('kpopify_ping_reset_burst'); ?>
            <button type="button" id="kpopify-ping-reset-btn" class="button button-secondary">Réinitialiser le verrou burst</button>
            <button type="submit" id="kpopify-ping-reset-confirm" class="button button-secondary" style="display:none;color:#b32d2e;border-color:#b32d2e">⚠ Confirmer</button>
        </form>
    </div>
    <?php
}
