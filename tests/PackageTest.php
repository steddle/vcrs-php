<?php

use Steddle\Vcrs\Vcrs;

/**
 * Path repositories for both packages at the validator's version and for opis as the app's vendor holds it, so Composer
 * installs offline what the split publishes.
 *
 * @return list<array<string, mixed>>
 */
function packageRepositories(): array
{
    $vendor = dirname(__DIR__, 4).'/vendor';
    $folders = ['steddle/vcrs' => [standardFile(''), Vcrs::VERSION], 'steddle/vcrs-php' => [dirname(__DIR__), Vcrs::VERSION]];

    foreach (json_decode(file_get_contents("{$vendor}/composer/installed.json"), true)['packages'] as $package) {
        if (str_starts_with($package['name'], 'opis/')) {
            $folders[$package['name']] = ["{$vendor}/composer/{$package['install-path']}", $package['version']];
        }
    }

    $repositories = [['packagist.org' => false]];

    foreach ($folders as $name => [$folder, $version]) {
        $repositories[] = ['type' => 'path', 'url' => $folder, 'options' => ['symlink' => false, 'versions' => [$name => $version]]];
    }

    return $repositories;
}

it('installs beside the standard it reads and checks the example, from a project that requires it and from a clone of its own repository', function (string $route) {
    $directory = sys_get_temp_dir().'/vcrs-package-'.bin2hex(random_bytes(4));

    try {
        if ($route === 'project') {
            mkdir($directory);
            $manifest = ['require' => ['steddle/vcrs-php' => Vcrs::VERSION, 'steddle/vcrs' => Vcrs::VERSION]];
            $bin = 'vendor/bin/vcrs';
        } else {
            exec('cp -R '.escapeshellarg(dirname(__DIR__)).' '.escapeshellarg($directory));
            $manifest = json_decode(file_get_contents("{$directory}/composer.json"), true);
            $bin = 'bin/vcrs';
        }

        file_put_contents("{$directory}/composer.json", json_encode([...$manifest, 'repositories' => packageRepositories()], JSON_UNESCAPED_SLASHES));

        exec('cd '.escapeshellarg($directory).' && COMPOSER_ROOT_VERSION='.Vcrs::VERSION.' composer install --no-interaction --no-progress 2>&1', $installed, $installStatus);
        expect($installStatus)->toBe(0, implode("\n", $installed));

        $templates = "{$directory}/vendor/steddle/vcrs/templates";
        exec('cd '.escapeshellarg($directory)." && php {$bin} check {$templates}/register.json --records={$templates}/records.csv --expect={$templates}/expected.json 2>&1", $checked, $status);

        expect($status)->toBe(0, implode("\n", $checked));
    } finally {
        exec('rm -rf '.escapeshellarg($directory));
    }
})->with(['project', 'clone']);

it('requires the standard at its own version, which a tag gives both repositories', function () {
    $package = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);
    $standard = json_decode(file_get_contents(standardFile('composer.json')), true);

    expect($package['require'][$standard['name']])->toBe('self.version')
        ->and($package['license'])->toBe('MIT')
        ->and($standard['license'])->toBe('CC-BY-4.0');
});
