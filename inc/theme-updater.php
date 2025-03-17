<?php
// Sécurité : empêcher l'accès direct
if (!defined('ABSPATH')) exit;

add_filter('site_transient_update_plugins', function ($transient) {
  if (!is_object($transient)) $transient = new stdClass();

  // Définition dynamique du plugin
  $organisation_github = 'ocade-fusion-system'; // ORGANISATION_GITHUB
  $plugin_slug = 'ocade-blocks'; // DEPOT_GITHUB
  $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_slug . '/' . $plugin_slug . '.php';
  $github_api_url = "https://api.github.com/repos/$organisation_github/$plugin_slug/releases/latest";
  $repo_url = "https://github.com/$organisation_github/$plugin_slug";
  $zip_url = $repo_url . '/releases/latest/download/' . $plugin_slug . '.zip';

  // Icônes dynamiques
  $icon_base_url = 'https://raw.githubusercontent.com/' . $organisation_github . '/' . $plugin_slug . '/master/assets/icons/';
  $icons = [
    'svg'  => $icon_base_url . 'icon.svg',
    '1x'   => $icon_base_url . 'icon-1x.png',
    '2x'   => $icon_base_url . 'icon-2x.png',
    '3x'   => $icon_base_url . 'icon-3x.png',
    '4x'   => $icon_base_url . 'icon-4x.png',
    '5x'   => $icon_base_url . 'icon-5x.png',
  ];

  // Charger la fonction pour obtenir les infos du plugin 
  if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
  if (!file_exists($plugin_file)) return $transient; // Sécurité : si le fichier du plugin n'existe pas

  $plugin_data = get_plugin_data($plugin_file);
  $current_version = $plugin_data['Version'];

  // Récupérer la version distante (mise en cache pour éviter les requêtes répétées)
  $remote_version = get_transient($plugin_slug . '_remote_version');
  if (!$remote_version) {
    // Récupération des assets de la dernière release via l'API GitHub
    $response = wp_remote_get($github_api_url, [
      'headers' => [
        'User-Agent' => 'WordPress' // GitHub requiert un User-Agent personnalisé
      ]
    ]);

    if (is_wp_error($response)) {
      error_log('Erreur lors de la récupération de la release GitHub : ' . $response->get_error_message());
      return $transient;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!empty($body['assets'])) {
      foreach ($body['assets'] as $asset) {
        if ($asset['name'] === 'version.txt') {
          $version_file_url = $asset['browser_download_url'];
          break;
        }
      }
    }

    if (!empty($version_file_url)) {
      $version_response = wp_remote_get($version_file_url);

      if (!is_wp_error($version_response)) {
        $remote_version = trim(wp_remote_retrieve_body($version_response));
        $remote_version = preg_replace('/[^0-9.]/', '', $remote_version);

        if (!empty($remote_version)) {
          set_transient($plugin_slug . '_remote_version', $remote_version, 6 * HOUR_IN_SECONDS);
        } else {
          error_log('La version récupérée depuis les assets est vide.');
        }
      }
    } else {
      error_log('Aucun fichier version.txt trouvé dans les assets de la dernière release.');
    }
  }

  // Comparaison des versions
  if (!empty($remote_version) && version_compare($remote_version, $current_version, '>')) {
    $plugin_basename = plugin_basename($plugin_file);

    $transient->response[$plugin_basename] = (object) [
      'slug'        => $plugin_slug,
      'plugin'      => $plugin_basename,
      'new_version' => $remote_version,
      'url'         => $repo_url,
      'package'     => $zip_url,
      'icons'       => $icons,
    ];
  }

  return $transient;
});

add_action('upgrader_process_complete', function ($upgrader_object, $options) {
  if ($options['action'] === 'update' && $options['type'] === 'plugin') {
    delete_transient('ocade-blocks_remote_version'); // Supprime le cache de version après mise à jour 
  }
}, 10, 2);
