<?php
namespace FacturaScripts\Plugins\SolwedConnect\Lib;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;

/**
 * Carga el catálogo de plugins desde el store Solwed.
 *
 * URL resuelta en este orden:
 *   1. Env var SOLWED_PLUGIN_STORE_URL  (Docker managed)
 *   2. AppSettings solwedconnect.plugin_store_url
 *   3. GitHub SolWed-es/SolwedPlugins-container (fallback público)
 *
 * Reemplaza Forja::plugins() en AdminPlugins.
 */
class SolwedGitHubPlugins
{
    const CACHE_KEY = 'solwed_plugin_list';
    const JSON_URL_GITHUB = 'https://raw.githubusercontent.com/SolWed-es/SolwedPlugins-container/main/plugin-list.json';

    public static function getStoreBaseUrl(): string
    {
        $env = getenv('SOLWED_PLUGIN_STORE_URL');
        if (!empty($env)) {
            return rtrim($env, '/');
        }
        $settings = Tools::settings('solwedconnect', 'plugin_store_url', '');
        if (!empty($settings)) {
            return rtrim($settings, '/');
        }
        return 'https://raw.githubusercontent.com/SolWed-es/SolwedPlugins-container/main';
    }

    private static function getCatalogUrl(): string
    {
        $base = self::getStoreBaseUrl();
        // GitHub raw URLs ya incluyen el fichero, los demás no
        if (str_contains($base, 'githubusercontent.com')) {
            return self::JSON_URL_GITHUB;
        }
        return $base . '/plugin-list.json';
    }

    public static function getPluginMap(): array
    {
        $map = [];
        foreach (self::fetchPlugins() as $plugin) {
            if (!empty($plugin['name'])) {
                $map[$plugin['name']] = $plugin;
            }
        }
        return $map;
    }

    public static function getList(): array
    {
        return self::fetchPlugins();
    }

    public static function getDownloadUrl(string $name): string
    {
        // Primero buscar en el catálogo
        foreach (self::fetchPlugins() as $plugin) {
            if ($plugin['name'] === $name && !empty($plugin['download_url'])) {
                return $plugin['download_url'];
            }
        }
        // Fallback: construir desde base URL
        $base = self::getStoreBaseUrl();
        if (str_contains($base, 'githubusercontent.com')) {
            return 'https://github.com/SolWed-es/SolwedPlugins-container/raw/main/zip/' . $name . '.zip';
        }
        return $base . '/zip/' . $name . '.zip';
    }

    public static function clearCache(): void
    {
        Cache::delete(self::CACHE_KEY);
    }

    private static function fetchPlugins(): array
    {
        return Cache::remember(self::CACHE_KEY, function () {
            $url = self::getCatalogUrl();
            $response = Http::get($url)
                ->setTimeout(10)
                ->setHeader('User-Agent', 'FacturaSolwed/1.0');
            if ($response->failed()) {
                return [];
            }
            $data = json_decode($response->body(), true);
            if (is_array($data) && isset($data['plugins'])) {
                return $data['plugins'];
            }
            // Formato array directo (GitHub container)
            if (is_array($data)) {
                return $data;
            }
            return [];
        });
    }
}
