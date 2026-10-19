<?php

function vectors(): array
{
    $vectors = [];

    foreach (glob(dirname(__DIR__, 2).'/vcrs/vectors/*/*.json') as $path) {
        $vectors[basename(dirname($path)).'/'.basename($path, '.json')] = [$path];
    }

    return $vectors;
}

function standardFile(string $path): string
{
    return dirname(__DIR__, 2).'/vcrs/'.$path;
}

function template(string $name): string
{
    return dirname(__DIR__, 2).'/vcrs/templates/'.$name;
}
