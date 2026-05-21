<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2025 SolWed <dev@solwed.es>
 */

namespace FacturaScripts\Core\Internal;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;

/**
 * Fetches the SolWed plugin list from SolWed-es/SolwedPlugins-container.
 * Used by AdminPlugins to populate the "Portal SolWed" tab.
 */
class SolwedGitHubPlugins
{
    const CACHE_KEY = 'solwed_github_plugin_list';
    const JSON_URL  = 'https://raw.githubusercontent.com/SolWed-es/SolwedPlugins-container/main/plugin-list.json';

    /** Returns a name-indexed map of all SolWed GitHub plugins available for install. */
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

    /** Fallback download URL when download_url is not present in the plugin data. */
    public static function getDownloadUrl(string $name, string $version): string
    {
        return 'https://github.com/SolWed-es/SolwedPlugins-container/raw/main/zip/' . $name . '.zip';
    }

    private static function fetchPlugins(): array
    {
        return Cache::remember(self::CACHE_KEY, function () {
            $response = Http::get(self::JSON_URL)
                ->setTimeout(10)
                ->setHeader('User-Agent', 'FacturaScripts-SolWed/1.0');
            if ($response->failed()) {
                return [];
            }
            $data = json_decode($response->body(), true);
            return is_array($data) && isset($data['plugins']) ? $data['plugins'] : [];
        });
    }
}
