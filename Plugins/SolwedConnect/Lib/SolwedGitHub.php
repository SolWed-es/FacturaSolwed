<?php
namespace FacturaScripts\Plugins\SolwedConnect\Lib;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;
use FacturaScripts\Core\Kernel;

/**
 * Detecta y descarga actualizaciones del CORE desde el proxy Mind.
 * Los updates de plugins se gestionan via SolwedGitHubPlugins (catálogo propio).
 */
class SolwedGitHub
{
    const MIND_BUILDS_URL = 'https://mind.solwed.es/api/fs/builds';

    public static function canUpdateCore(): bool
    {
        $build = self::getCoreBuild();
        return !empty($build) && version_compare((string)$build['version'], (string)Kernel::version(), '>');
    }

    public static function getCoreBuild(): array
    {
        return Cache::remember('solwed_github_core', function () {
            try {
                $mindBuilds = Http::get(self::MIND_BUILDS_URL)
                    ->setTimeout(5)
                    ->setHeader('User-Agent', 'FacturaSolwed/' . Kernel::version())
                    ->json() ?? [];

                if (!is_array($mindBuilds)) {
                    return [];
                }

                foreach ($mindBuilds as $project) {
                    if (($project['source'] ?? '') === 'solwed-github') {
                        foreach ($project['builds'] ?? [] as $build) {
                            if ($build['stable'] ?? false) {
                                return $build;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Mind no disponible — no hay info de update
            }

            return [];
        });
    }
}
