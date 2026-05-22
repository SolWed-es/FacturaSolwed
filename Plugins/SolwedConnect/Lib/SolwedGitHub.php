<?php
namespace FacturaScripts\Plugins\SolwedConnect\Lib;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;
use FacturaScripts\Core\Kernel;

/**
 * Reemplaza Forja — updates del core y plugins desde GitHub SolWed-es.
 */
class SolwedGitHub
{
    const CORE_REPO = 'SolWed-es/facturascripts';
    const GITHUB_API_RELEASES = 'https://api.github.com/repos/%s/releases';
    const MIND_BUILDS_URL = 'https://mind.solwed.es/api/fs/builds';

    public static function canUpdateCore(): bool
    {
        $build = self::getCoreBuild();
        // BUG FIX: version_compare para evitar comparaciones float incorrectas
        return !empty($build) && version_compare((string)$build['version'], (string)Kernel::version(), '>');
    }

    public static function getCoreBuild(): array
    {
        return Cache::remember('solwed_github_core', function () {
            // 1. Intentar via Mind proxy
            $mindBuilds = Http::get(self::MIND_BUILDS_URL)->setTimeout(5)->json() ?? [];
            if (is_array($mindBuilds)) {
                foreach ($mindBuilds as $project) {
                    if (($project['source'] ?? '') === 'solwed-github') {
                        foreach ($project['builds'] ?? [] as $build) {
                            if ($build['stable'] ?? false) {
                                return $build;
                            }
                        }
                    }
                }
            }

            // 2. Fallback: GitHub API directa
            $http = self::apiRequest(sprintf(self::GITHUB_API_RELEASES, self::CORE_REPO) . '?per_page=10');
            if ($http->status() !== 200) {
                return [];
            }
            foreach ($http->json() ?? [] as $release) {
                $tag = $release['tag_name'] ?? '';
                if (!str_starts_with($tag, 'v')) {
                    continue;
                }
                $build = self::buildFromRelease($release);
                if (!empty($build)) {
                    return $build;
                }
            }
            return [];
        });
    }

    public static function getPluginBuild(string $repo, string $pluginName): array
    {
        return Cache::remember('solwed_github_plugin_' . md5($repo . $pluginName), function () use ($repo, $pluginName) {
            $http = self::apiRequest(sprintf(self::GITHUB_API_RELEASES, $repo));
            if ($http->status() !== 200) {
                return [];
            }
            $tagPrefix = $pluginName . '-v';
            foreach ($http->json() ?? [] as $release) {
                if (str_starts_with($release['tag_name'] ?? '', $tagPrefix)) {
                    return self::buildFromRelease($release, $pluginName . '-v');
                }
            }
            return [];
        });
    }

    private static function buildFromRelease(array $release, string $stripPrefix = 'v'): array
    {
        if (empty($release)) {
            return [];
        }
        $tag = $release['tag_name'] ?? '';
        // BUG FIX: no usar (float) — pierde precisión en versiones como "1.2.3" → 1.2
        // Guardamos la versión como string y comparamos con version_compare()
        $version = ltrim($tag, $stripPrefix);
        if (empty($version) || !preg_match('/^\d/', $version)) {
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
            'stable' => !($release['prerelease'] ?? false),
            'url' => $downloadUrl,
        ];
    }

    private static function apiRequest(string $url): Http
    {
        return Http::get($url)
            ->setTimeout(10)
            ->setHeader('Accept', 'application/vnd.github+json')
            ->setHeader('User-Agent', 'FacturaSolwed/' . Kernel::version())
            ->setHeader('X-GitHub-Api-Version', '2022-11-28');
    }
}
