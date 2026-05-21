<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2025 SolWed <dev@solwed.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Core\Internal;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;
use FacturaScripts\Core\Kernel;

class SolwedGitHub
{
    const CORE_REPO = 'SolWed-es/facturascripts';
    const RELEASE_BRANCH = 'solwed/production';
    const GITHUB_API_LATEST   = 'https://api.github.com/repos/%s/releases/latest';
    const GITHUB_API_RELEASES = 'https://api.github.com/repos/%s/releases';

    // Mind proxy — evita rate limits de GitHub API directa
    const MIND_BUILDS_URL = 'https://mind.solwed.es/api/fs/builds';

    // ── Core ──────────────────────────────────────────────────────────────────

    public static function canUpdateCore(): bool
    {
        $build = self::getCoreBuild();
        return !empty($build) && $build['version'] > Kernel::version();
    }

    public static function getCoreBuild(): array
    {
        return Cache::remember('solwed_github_core', function () {
            // 1. Intentar via Mind proxy (incluye builds de SolWed GitHub)
            $mindBuilds = Http::get(self::MIND_BUILDS_URL)->setTimeout(5)->json() ?? [];
            if (is_array($mindBuilds)) {
                foreach ($mindBuilds as $project) {
                    if (($project['source'] ?? '') === 'solwed-github' || ($project['project'] ?? 0) === 9999) {
                        $builds = $project['builds'] ?? [];
                        foreach ($builds as $build) {
                            if ($build['stable'] ?? false) {
                                return $build;
                            }
                        }
                        return $builds[0] ?? [];
                    }
                }
            }

            // 2. Fallback: GitHub API directa — primera release con tag v* y ZIP adjunto
            $http = self::apiRequest(sprintf(self::GITHUB_API_RELEASES, self::CORE_REPO) . '?per_page=10');
            if ($http->status() !== 200) {
                return [];
            }
            foreach ($http->json() ?? [] as $release) {
                $tag = $release['tag_name'] ?? '';
                if (!str_starts_with($tag, 'v')) {
                    continue;
                }
                $build = self::buildFromRelease($release, '');
                if (!empty($build)) {
                    return $build;
                }
            }
            return [];
        });
    }

    // ── Plugins ───────────────────────────────────────────────────────────────

    /**
     * Lee el campo github del facturascripts.ini del plugin.
     * Formato: "SolWed-es/facturascripts:SolwedTheme"
     */
    public static function getPluginRepo(Plugin $plugin): string
    {
        $iniPath = $plugin->folder() . DIRECTORY_SEPARATOR . 'facturascripts.ini';
        if (!file_exists($iniPath)) {
            return '';
        }
        $data = parse_ini_file($iniPath);
        return trim($data['github'] ?? '');
    }

    public static function getPluginBuild(Plugin $plugin): array
    {
        $githubField = self::getPluginRepo($plugin);
        if (empty($githubField)) {
            return [];
        }

        [$repo, $prefix] = self::parseGithubField($githubField);

        $release = Cache::remember('solwed_github_plugin_' . md5($githubField), function () use ($repo, $prefix) {
            return self::findRelease($repo, $prefix);
        });

        return self::buildFromRelease($release, $prefix);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * "SolWed-es/facturascripts:SolwedTheme" → ['SolWed-es/facturascripts', 'SolwedTheme']
     */
    private static function parseGithubField(string $field): array
    {
        $parts = explode(':', $field, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Busca en la lista de releases la primera cuyo tag empiece por "{prefix}-v".
     * GitHub devuelve las releases ordenadas de más reciente a más antigua.
     */
    private static function findRelease(string $repo, string $prefix): array
    {
        $http = self::apiRequest(sprintf(self::GITHUB_API_RELEASES, $repo));
        if ($http->status() !== 200) {
            return [];
        }

        $tagPrefix = $prefix . '-v';
        foreach ($http->json() ?? [] as $release) {
            if (str_starts_with($release['tag_name'] ?? '', $tagPrefix)) {
                return $release;
            }
        }

        return [];
    }

    private static function buildFromRelease(array $release, string $prefix): array
    {
        if (empty($release)) {
            return [];
        }

        $version = self::parseVersion($release['tag_name'] ?? '', $prefix);
        if ($version <= 0) {
            return [];
        }

        $downloadUrl = '';
        foreach ($release['assets'] ?? [] as $asset) {
            if (str_ends_with($asset['name'], '.zip')) {
                $downloadUrl = $asset['browser_download_url'] ?? '';
                break;
            }
        }
        if (empty($downloadUrl)) {
            return [];
        }

        return [
            'version' => $version,
            'stable'  => !($release['prerelease'] ?? false),
            'beta'    => (bool)($release['prerelease'] ?? false),
            'url'     => $downloadUrl,
        ];
    }

    private static function apiRequest(string $url): Http
    {
        return Http::get($url)
            ->setTimeout(10)
            ->setHeader('Accept', 'application/vnd.github+json')
            ->setHeader('User-Agent', 'FacturaScripts-SolWed/' . Kernel::version())
            ->setHeader('X-GitHub-Api-Version', '2022-11-28');
    }

    private static function parseVersion(string $tagName, string $prefix): float
    {
        // Core:   "v2025.93"           → strip "v"              → 2025.93
        // Plugin: "SolwedTheme-v1.73"  → strip "SolwedTheme-v"  → 1.73
        $strip = empty($prefix) ? 'v' : $prefix . '-v';
        if (str_starts_with($tagName, $strip)) {
            $tagName = substr($tagName, strlen($strip));
        }
        return (float)$tagName;
    }
}
